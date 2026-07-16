<?php

declare(strict_types=1);

namespace Bladestan\Console;

use Bladestan\Console\Extraction\ViewSignatureCollectedDataRule;
use Illuminate\Console\Command;
use JsonException;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;
use Symfony\Component\Process\Process;

/**
 * Generates `@bladestan-signature` docblocks by harvesting the types PHPStan
 * infers at each render site, so signatures reflect what controllers actually
 * pass rather than a hand-guessed contract.
 *
 * Rather than editing project source to dump types, this runs PHPStan once with
 * a collector added (see {@see \Bladestan\Console\Extraction\ViewDataCollector}
 * and config/generate-signatures.neon). The collector reads the real type of
 * every render site the analysis already understands and reports one signature
 * per view; this command reads those back from the JSON output and writes them
 * into the templates. Nothing in the project is modified except the templates
 * that get a signature.
 *
 * By default only templates without a signature are written; pass `--force` to
 * overwrite and `--dry-run` to report without writing. Templates reached only
 * through `@include` or as class components are not covered, since they are not
 * rendered from an analysable PHP call site.
 */
final class GenerateBladeSignaturesCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'bladestan:generate-signatures
        {--path=* : Directories or files to scan for render calls (default: app)}
        {--phpstan=vendor/bin/phpstan : Path to the PHPStan binary}
        {--config= : PHPStan config file (auto-detected when omitted)}
        {--force : Overwrite templates that already have a signature}
        {--dry-run : Report what would change without writing templates}';

    /**
     * @var string
     */
    protected $description = 'Generate @bladestan-signature docblocks from types inferred at view() call sites';

    public function handle(): int
    {
        $configFile = $this->resolveConfigFile();
        if ($configFile === null) {
            $this->error(
                'No PHPStan config found. Pass --config, or create a phpstan.neon that includes Bladestan.',
            );

            return self::FAILURE;
        }

        $scanPaths = $this->scanPaths();
        $this->info(sprintf('Harvesting view() types with PHPStan (scanning %s)...', implode(', ', $scanPaths)));

        $payloads = $this->harvest($configFile, $scanPaths);
        if ($payloads === null) {
            return self::FAILURE;
        }

        $this->line(sprintf('  found %d view(s) rendered from PHP', count($payloads)));

        $written = 0;
        $skipped = 0;
        $empty = 0;
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        foreach ($payloads as $payload) {
            // A view rendered with no data has no contract to declare. An empty
            // signature is indistinguishable from no signature (both leave the
            // call site unchecked), so writing one would only add noise.
            if ($payload['variables'] === []) {
                $empty++;
                continue;
            }

            if (! $this->applySignature($payload, $force, $dryRun)) {
                $skipped++;
                continue;
            }

            $written++;
            $this->line('  ' . ($dryRun ? 'would sign' : 'signed') . ": {$payload['view']}");
        }

        $this->newLine();
        $this->info(sprintf(
            'Done. %d signed, %d already-signed skipped, %d rendered with no data.',
            $written,
            $skipped,
            $empty,
        ));

        return self::SUCCESS;
    }

    /**
     * The PHPStan config to analyse under, resolved to an absolute path.
     * `--config` wins; otherwise the usual project config names are tried.
     */
    private function resolveConfigFile(): ?string
    {
        $configured = $this->stringOption('config');
        if ($configured !== '') {
            $absolute = $this->absolute($configured);

            return is_file($absolute) ? $absolute : null;
        }

        foreach (['phpstan.neon', 'phpstan.neon.dist', 'phpstan.dist.neon'] as $candidate) {
            $absolute = $this->absolute($candidate);
            if (is_file($absolute)) {
                return $absolute;
            }
        }

        return null;
    }

    /**
     * @return list<string> Absolute scan paths (directories or files).
     */
    private function scanPaths(): array
    {
        /** @var list<string> $paths */
        $paths = (array) $this->option('path');
        if ($paths === []) {
            $paths = ['app'];
        }

        return array_values(array_filter(
            array_map(fn (string $path): string => $this->absolute($path), $paths),
            fn (string $path): bool => file_exists($path),
        ));
    }

    /**
     * Run PHPStan once with the signature collector registered and read the
     * harvested signatures back from its JSON output.
     *
     * @param list<string> $scanPaths
     * @return list<array{template: string, view: string, variables: array<string, string>}>|null
     *         Null when the analysis could not be run or its output could not be
     *         understood (already reported to the user).
     */
    private function harvest(string $configFile, array $scanPaths): ?array
    {
        if ($scanPaths === []) {
            $this->warn('  no scan path exists; nothing to analyse.');

            return [];
        }

        $tmpDir = sys_get_temp_dir() . '/bladestan-signatures-' . md5($this->basePath());
        if (! is_dir($tmpDir) && ! mkdir($tmpDir, 0o777, true) && ! is_dir($tmpDir)) {
            $this->error("  could not create a temporary directory at {$tmpDir}");

            return null;
        }

        $neonFile = $tmpDir . '/config.neon';
        file_put_contents($neonFile, $this->buildAnalysisConfig($configFile, $scanPaths, $tmpDir . '/cache'));

        try {
            $process = new Process(
                [
                    $this->absolute($this->stringOption('phpstan')),
                    'analyse',
                    '--error-format=json',
                    '--no-progress',
                    '--no-interaction',
                    '-c',
                    $neonFile,
                ],
                $this->basePath(),
            );
            $process->setTimeout(null);
            $process->run();
            $output = $process->getOutput();
            $errorOutput = $process->getErrorOutput();
        } catch (ProcessException $processException) {
            $this->error('  PHPStan could not be run: ' . $processException->getMessage());

            return null;
        } finally {
            @unlink($neonFile);
        }

        return $this->parsePayloads($output, $errorOutput);
    }

    /**
     * The throwaway config that adds the collector to the project's own config.
     *
     * `paths!` replaces the project's analysis paths (the `!` overrides the
     * merge) so only the scanned code is analysed, never the compiled-template
     * directory. A dedicated `tmpDir` keeps this run's result cache separate
     * from the project's normal one, so neither invalidates the other.
     *
     * @param list<string> $scanPaths
     */
    private function buildAnalysisConfig(string $configFile, array $scanPaths, string $cacheDir): string
    {
        $fragment = dirname(__DIR__, 2) . '/config/generate-signatures.neon';

        $lines = [
            'includes:',
            '    - ' . $configFile,
            '    - ' . $fragment,
            'parameters:',
            '    tmpDir: ' . $cacheDir,
            '    paths!:',
        ];
        foreach ($scanPaths as $scanPath) {
            $lines[] = '        - ' . $scanPath;
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Pull the signature payloads out of PHPStan's JSON error output. Every
     * other message (real diagnostics, if any) is ignored.
     *
     * @return list<array{template: string, view: string, variables: array<string, string>}>|null
     */
    private function parsePayloads(string $output, string $errorOutput): ?array
    {
        try {
            /** @var array{files?: array<string, array{messages?: list<array{message?: string, identifier?: string}>}>} $decoded */
            $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->error('  PHPStan did not return analysable output. Its output was:');
            $this->line(trim($output . "\n" . $errorOutput));

            return null;
        }

        $prefix = ViewSignatureCollectedDataRule::SENTINEL . ' ';
        $payloads = [];
        foreach ($decoded['files'] ?? [] as $fileReport) {
            foreach ($fileReport['messages'] ?? [] as $message) {
                $text = $message['message'] ?? '';
                if (! str_starts_with($text, $prefix)) {
                    continue;
                }

                try {
                    /** @var array{template: string, view: string, variables: array<string, string>} $payload */
                    $payload = json_decode(substr($text, strlen($prefix)), true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    continue;
                }

                $payloads[] = $payload;
            }
        }

        return $payloads;
    }

    /**
     * @param array{template: string, view: string, variables: array<string, string>} $payload
     */
    private function applySignature(array $payload, bool $force, bool $dryRun): bool
    {
        $contents = @file_get_contents($payload['template']);
        if ($contents === false) {
            $this->warn("  could not read template for view [{$payload['view']}]");

            return false;
        }

        if (str_contains($contents, '@bladestan-signature') && ! $force) {
            return false;
        }

        if (! $dryRun) {
            file_put_contents($payload['template'], $this->buildSignature($payload['variables']) . $contents);
        }

        return true;
    }

    /**
     * @param array<string, string> $variables
     */
    private function buildSignature(array $variables): string
    {
        // blade-formatter indents the PHP inside an @php block by four spaces, so
        // match that here to spare anyone running the formatter a reformat diff.
        $lines = ['@php', '    /**', '     * @bladestan-signature'];
        foreach ($variables as $name => $type) {
            $lines[] = '     * @var ' . $type . ' $' . $name;
        }

        $lines[] = '     */';
        $lines[] = '@endphp';

        return implode("\n", $lines) . "\n";
    }

    private function absolute(string $path): string
    {
        return str_starts_with($path, '/') ? $path : $this->basePath() . '/' . $path;
    }

    /**
     * The project root the command operates on: the directory PHPStan runs in
     * and the anchor for relative config, scan, and binary paths.
     *
     * This is the working directory the command was invoked from, not the
     * framework's base path. Under Testbench (how a package runs its own
     * commands) the base path points at the throwaway skeleton app, while the
     * project being analysed, its `phpstan.neon`, and its `vendor/bin/phpstan`
     * all live in the working directory.
     */
    private function basePath(): string
    {
        return getcwd() ?: $this->laravel->basePath();
    }

    private function stringOption(string $name): string
    {
        $value = $this->option($name);

        return is_string($value) ? $value : '';
    }
}
