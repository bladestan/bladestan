<?php

declare(strict_types=1);

namespace Bladestan\Console;

use Bladestan\Console\ValueObject\RenderSite;
use FilesystemIterator;
use Illuminate\Console\Command;
use Illuminate\Contracts\View\Factory as ViewFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;
use Symfony\Component\Process\Process;
use UnexpectedValueException;

/**
 * Generates `@bladestan-signature` docblocks by harvesting the types PHPStan
 * infers at each `view()` render site, so signatures reflect what controllers
 * actually pass rather than a hand-guessed contract.
 *
 * The flow needs no hand-written types:
 *   1. Find every render site that names a template and passes a data argument.
 *   2. Inject `\PHPStan\dumpType(<that data>)` before each call.
 *   3. Run PHPStan once. Each dump returns an `array{var: Type, ...}` shape
 *      keyed by the exact variable names the view receives.
 *   4. Normalize each type to parser-safe, fully-qualified PHPDoc and write (or
 *      merge) the signature into the template, so it needs no `use` import.
 *   5. Restore the injected source files.
 *
 * By default only templates without a signature are written; pass `--force` to
 * overwrite, `--dry-run` to report without writing. `@include` partials and
 * class components are not yet covered (they are the natural next passes).
 */
final class GenerateBladeSignaturesCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'bladestan:generate-signatures
        {--path=* : Directories to scan for render calls (default: app)}
        {--phpstan=vendor/bin/phpstan : Path to the PHPStan binary}
        {--config=phpstan.neon : PHPStan config file}
        {--force : Overwrite templates that already have a signature}
        {--dry-run : Report what would change without writing templates}';

    /**
     * @var string
     */
    protected $description = 'Generate @bladestan-signature docblocks from types inferred at view() call sites';

    private const MARKER = 'BLADESIG';

    public function __construct(
        private readonly RenderSiteFinder $renderSiteFinder,
        private readonly DumpedShapeParser $dumpedShapeParser,
    ) {
        parent::__construct();
    }

    public function handle(ViewFactory $viewFactory): int
    {
        $this->info('Scanning for view render calls...');

        $renderSites = [];
        foreach ($this->phpFiles($this->scanPaths()) as $file) {
            foreach ($this->renderSiteFinder->find($file) as $renderSite) {
                $renderSites[] = $renderSite;
            }
        }

        $this->line(sprintf('  found %d render sites with data arguments', count($renderSites)));
        if ($renderSites === []) {
            return self::SUCCESS;
        }

        /** @var array<string, list<RenderSite>> $sitesByFile */
        $sitesByFile = [];
        foreach ($renderSites as $renderSite) {
            $sitesByFile[$renderSite->filePath][] = $renderSite;
        }

        // Inject dumpType calls, keeping the originals so they can be restored.
        $backups = [];
        foreach ($sitesByFile as $file => $fileSites) {
            $original = @file_get_contents($file);
            if ($original === false) {
                continue;
            }

            $backups[$file] = $original;
            file_put_contents($file, $this->inject($original, $fileSites));
        }

        try {
            $this->info('Running PHPStan to harvest types (this analyses the injected files once)...');
            $shapesByView = $this->harvest(array_keys($backups));
        } finally {
            foreach ($backups as $file => $original) {
                file_put_contents($file, $original);
            }
        }

        $this->line(sprintf('  harvested type dumps for %d views', count($shapesByView)));

        $written = 0;
        $skipped = 0;
        $missing = 0;
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        foreach ($shapesByView as $viewName => $shapes) {
            $templateFile = $this->resolveTemplate($viewFactory, $viewName);
            if ($templateFile === null) {
                $missing++;
                $this->warn("  no template file for view [{$viewName}]");
                continue;
            }

            $signature = $this->buildSignature($this->dumpedShapeParser->mergeShapes($shapes));
            if (! $this->applySignature($templateFile, $signature, $force, $dryRun)) {
                $skipped++;
                continue;
            }

            $written++;
            $this->line('  ' . ($dryRun ? 'would sign' : 'signed') . ": {$viewName}");
        }

        $this->newLine();
        $this->info(sprintf(
            'Done. %d signed, %d already-signed skipped, %d without a template file.',
            $written,
            $skipped,
            $missing,
        ));

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function scanPaths(): array
    {
        /** @var list<string> $paths */
        $paths = (array) $this->option('path');
        if ($paths === []) {
            $paths = ['app'];
        }

        return array_map(fn (string $path): string => $this->absolute($path), $paths);
    }

    /**
     * @param list<string> $paths
     * @return iterable<string>
     */
    private function phpFiles(array $paths): iterable
    {
        foreach ($paths as $path) {
            if (is_file($path)) {
                yield $path;
                continue;
            }

            if (! is_dir($path)) {
                continue;
            }

            try {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                );
            } catch (UnexpectedValueException) {
                continue;
            }

            /** @var SplFileInfo $fileInfo */
            foreach ($iterator as $fileInfo) {
                if ($fileInfo->isFile() && $fileInfo->getExtension() === 'php') {
                    yield $fileInfo->getPathname();
                }
            }
        }
    }

    /**
     * @param list<RenderSite> $renderSites
     */
    private function inject(string $code, array $renderSites): string
    {
        // Apply from the highest offset down so earlier offsets stay valid.
        usort($renderSites, fn (RenderSite $a, RenderSite $b): int => $b->statementStartPos <=> $a->statementStartPos);

        foreach ($renderSites as $renderSite) {
            $dataSource = substr($code, $renderSite->dataStartPos, $renderSite->dataEndPos - $renderSite->dataStartPos + 1);
            $dataSource = preg_replace('/\s+/', ' ', $dataSource) ?? $dataSource;
            $indent = $this->indentAt($code, $renderSite->statementStartPos);
            $injection = '\\PHPStan\\dumpType(' . $dataSource . '); /* ' . self::MARKER . ':' . $renderSite->viewName . " */\n" . $indent;
            $code = substr($code, 0, $renderSite->statementStartPos) . $injection . substr($code, $renderSite->statementStartPos);
        }

        return $code;
    }

    private function indentAt(string $code, int $offset): string
    {
        $lineStart = strrpos(substr($code, 0, $offset), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;

        $prefix = substr($code, $lineStart, $offset - $lineStart);

        return preg_match('/^\s*/', $prefix, $matches) === 1 ? $matches[0] : '';
    }

    /**
     * Run PHPStan over the injected files and map each dumped shape back to the
     * view whose marker sits on the dumped line.
     *
     * @param list<string> $files
     * @return array<string, list<array<string, array{type: string, optional: bool}>>>
     */
    private function harvest(array $files): array
    {
        try {
            $process = new Process(
                array_merge(
                    [$this->absolute($this->stringOption('phpstan')), 'analyse', '--error-format=raw', '--no-progress', '-c', $this->stringOption('config')],
                    $files,
                ),
                $this->basePath(),
            );
            $process->setTimeout(null);
            $process->run();
            $output = $process->getOutput() . "\n" . $process->getErrorOutput();
        } catch (ProcessException $processException) {
            $this->warn('  PHPStan could not be run: ' . $processException->getMessage());

            return [];
        }

        /** @var array<string, list<string>> $sourceLineCache */
        $sourceLineCache = [];
        /** @var array<string, list<array<string, array{type: string, optional: bool}>>> $shapesByView */
        $shapesByView = [];

        foreach (explode("\n", $output) as $line) {
            if (preg_match('#^(.+):(\d+):Dumped type: (.*)$#', $line, $matches) !== 1) {
                continue;
            }

            $file = $matches[1];
            $lineNumber = (int) $matches[2];
            $sourceLineCache[$file] ??= @file($file) ?: [];
            $sourceLine = $sourceLineCache[$file][$lineNumber - 1] ?? '';

            if (preg_match('/' . self::MARKER . ':(\S+)\s*\*\//', $sourceLine, $markerMatch) !== 1) {
                continue;
            }

            $shape = $this->dumpedShapeParser->parseShape($matches[3]);
            if ($shape === null) {
                continue;
            }

            $shapesByView[$markerMatch[1]][] = $shape;
        }

        return $shapesByView;
    }

    private function resolveTemplate(ViewFactory $viewFactory, string $viewName): ?string
    {
        // exists() consults the same finder without throwing, so a view that
        // resolves only at runtime (a dynamic name, a package view we can't map)
        // is skipped rather than aborting the command.
        if (! $viewFactory->exists($viewName)) {
            return null;
        }

        return $viewFactory->getFinder()
            ->find($viewName);
    }

    /**
     * @param array<string, string> $variables
     */
    private function buildSignature(array $variables): string
    {
        $lines = ['@php', '/**', ' * @bladestan-signature'];
        foreach ($variables as $name => $type) {
            $lines[] = ' * @var ' . $type . ' $' . $name;
        }

        $lines[] = ' */';
        $lines[] = '@endphp';

        return implode("\n", $lines) . "\n";
    }

    private function applySignature(string $file, string $signature, bool $force, bool $dryRun): bool
    {
        $contents = @file_get_contents($file);
        if ($contents === false) {
            return false;
        }

        if (str_contains($contents, '@bladestan-signature') && ! $force) {
            return false;
        }

        if (! $dryRun) {
            file_put_contents($file, $signature . $contents);
        }

        return true;
    }

    private function absolute(string $path): string
    {
        return str_starts_with($path, '/') ? $path : $this->basePath() . '/' . $path;
    }

    private function basePath(): string
    {
        return $this->laravel->basePath();
    }

    private function stringOption(string $name): string
    {
        $value = $this->option($name);

        return is_string($value) ? $value : '';
    }
}
