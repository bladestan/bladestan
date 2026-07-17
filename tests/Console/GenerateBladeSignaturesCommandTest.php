<?php

declare(strict_types=1);

namespace Bladestan\Tests\Console;

use Bladestan\Compiler\SignatureExtractor;
use Bladestan\Console\Extraction\ViewSignatureCollectedDataRule;
use Bladestan\Console\GenerateBladeSignaturesCommand;
use Illuminate\Console\OutputStyle;
use PHPStan\Testing\PHPStanTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Covers the command's pure decision logic — the mixed-type check, signature
 * rendering, payload parsing, throwaway-config building, and the force/dry-run
 * write rules — without the PHPStan subprocess that {@see laravel-test.sh}
 * drives end to end.
 */
final class GenerateBladeSignaturesCommandTest extends PHPStanTestCase
{
    private GenerateBladeSignaturesCommand $generateBladeSignaturesCommand;

    private string $tmpDir;

    /**
     * @return list<string>
     */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../../config/extension.neon'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->generateBladeSignaturesCommand = new GenerateBladeSignaturesCommand(
            self::getContainer()->getByType(SignatureExtractor::class),
        );
        $this->tmpDir = sys_get_temp_dir() . '/bladestan-generate-cmd-' . uniqid();
        mkdir($this->tmpDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->tmpDir);

        parent::tearDown();
    }

    /**
     * @param array<string, string> $variables
     */
    #[DataProvider('mixedTypeProvider')]
    public function testCarriesOnlyMixedTypes(array $variables, bool $expected): void
    {
        self::assertSame($expected, $this->invoke('carriesOnlyMixedTypes', $variables));
    }

    /**
     * @return iterable<string, array{array<string, string>, bool}>
     */
    public static function mixedTypeProvider(): iterable
    {
        yield 'all mixed' => [[
            'a' => 'mixed',
            'b' => 'mixed',
        ], true];
        yield 'nullable mixed' => [[
            'a' => '?mixed',
        ], true];
        yield 'mixed-or-null union' => [[
            'a' => 'mixed|null',
        ], true];
        yield 'one concrete' => [[
            'a' => 'mixed',
            'b' => 'string',
        ], false];
        yield 'concrete in union' => [[
            'a' => 'string|null',
        ], false];
    }

    public function testBuildSignatureRendersAPhpBlock(): void
    {
        $signature = $this->invoke('buildSignature', [
            'title' => 'string',
            'user' => 'App\Models\User|null',
        ]);

        self::assertSame(
            "@php\n"
            . "    /**\n"
            . "     * @bladestan-signature\n"
            . "     * @var string \$title\n"
            . "     * @var App\Models\User|null \$user\n"
            . "     */\n"
            . "@endphp\n",
            $signature,
        );
    }

    public function testParsePayloadsExtractsSentinelMessagesAndIgnoresTheRest(): void
    {
        $payload = [
            'template' => '/views/home.blade.php',
            'view' => 'home',
            'variables' => [
                'x' => 'int',
            ],
        ];
        $output = (string) json_encode([
            'files' => [
                '/views/home.blade.php' => [
                    'messages' => [
                        [
                            'message' => 'A real diagnostic that is not ours',
                        ],
                        [
                            'message' => ViewSignatureCollectedDataRule::SENTINEL . ' ' . json_encode($payload),
                        ],
                        [
                            'message' => ViewSignatureCollectedDataRule::SENTINEL . ' {not valid json',
                        ],
                    ],
                ],
            ],
        ]);

        $payloads = $this->invoke('parsePayloads', $output, '');

        // The real diagnostic and the malformed sentinel are both dropped; only
        // the well-formed payload survives.
        self::assertSame([$payload], $payloads);
    }

    public function testParsePayloadsReturnsEmptyWhenThereAreNoFiles(): void
    {
        self::assertSame([], $this->invoke('parsePayloads', '{"files": []}', ''));
    }

    public function testBuildAnalysisConfigConcatenatesPathsWithoutAnOverride(): void
    {
        $config = $this->invoke('buildAnalysisConfig', '/project/phpstan.neon', null, '/tmp/cache');
        self::assertIsString($config);

        self::assertStringContainsString('    - /project/phpstan.neon', $config);
        self::assertStringContainsString('config/generate-signatures.neon', $config);
        self::assertStringContainsString('tmpDir: /tmp/cache', $config);
        // Null scan paths concatenate onto the project's own `paths`, so a plain
        // `paths:` key is used, never the `paths!` override.
        self::assertStringContainsString("    paths:\n", $config);
        self::assertStringNotContainsString('paths!', $config);
        self::assertStringContainsString('.bladestan', $config);
    }

    public function testBuildAnalysisConfigOverridesPathsWhenScanPathsAreGiven(): void
    {
        $config = $this->invoke('buildAnalysisConfig', '/project/phpstan.neon', ['/scan/a', '/scan/b'], '/tmp/cache');
        self::assertIsString($config);

        // Explicit `--path` narrows the scan, so `paths!` replaces the project's
        // own paths with the given ones plus the compiled-template directory.
        self::assertStringContainsString('    paths!:', $config);
        self::assertStringContainsString('        - /scan/a', $config);
        self::assertStringContainsString('        - /scan/b', $config);
        self::assertStringContainsString('.bladestan', $config);
    }

    public function testApplySignatureWritesToAnUnsignedTemplate(): void
    {
        $template = $this->writeTemplate('unsigned.blade.php', "<p>{{ \$title }}</p>\n");

        $wrote = $this->invoke('applySignature', $this->payload($template), false, false);

        self::assertTrue($wrote);
        $contents = (string) file_get_contents($template);
        self::assertStringContainsString('@bladestan-signature', $contents);
        self::assertStringContainsString('@var string $title', $contents);
        self::assertStringContainsString('<p>{{ $title }}</p>', $contents);
    }

    public function testApplySignatureSkipsAnAlreadySignedTemplateWithoutForce(): void
    {
        $template = $this->writeTemplate('signed.blade.php', "<p>{{ \$title }}</p>\n");
        $this->invoke('applySignature', $this->payload($template), false, false);
        $signed = (string) file_get_contents($template);

        $wrote = $this->invoke('applySignature', $this->payload($template), false, false);

        self::assertFalse($wrote);
        self::assertSame($signed, file_get_contents($template), 'A signed template was rewritten without --force');
    }

    public function testApplySignatureReplacesTheSignatureWithForce(): void
    {
        $template = $this->writeTemplate('reforce.blade.php', "<p>{{ \$title }}</p>\n");
        $this->invoke('applySignature', $this->payload($template, [
            'title' => 'string',
        ]), false, false);

        $wrote = $this->invoke('applySignature', $this->payload($template, [
            'title' => 'int',
        ]), true, false);

        self::assertTrue($wrote);
        $contents = (string) file_get_contents($template);
        self::assertStringContainsString('@var int $title', $contents);
        // The old block is replaced, not stacked: only one signature marker.
        self::assertSame(1, substr_count($contents, '@bladestan-signature'));
    }

    public function testApplySignatureLeavesTheFileUntouchedOnADryRun(): void
    {
        $template = $this->writeTemplate('dry.blade.php', "<p>{{ \$title }}</p>\n");

        $wrote = $this->invoke('applySignature', $this->payload($template), false, true);

        self::assertTrue($wrote, 'A dry run still reports what it would change');
        self::assertSame("<p>{{ \$title }}</p>\n", file_get_contents($template), 'A dry run must not write');
    }

    public function testApplySignatureWarnsWhenTheTemplateCannotBeRead(): void
    {
        $this->bindIo();

        $wrote = $this->invoke('applySignature', $this->payload($this->tmpDir . '/missing.blade.php'), false, false);

        self::assertFalse($wrote);
    }

    public function testResolveConfigFileReturnsTheExplicitConfigWhenItExists(): void
    {
        // getcwd() (the command's project root under test) is the repo root,
        // which ships a phpstan.neon the --config value can point at.
        $this->bindIo([
            '--config' => 'phpstan.neon',
        ]);

        $resolved = $this->invoke('resolveConfigFile');

        self::assertIsString($resolved);
        self::assertStringEndsWith('/phpstan.neon', $resolved);
    }

    public function testResolveConfigFileReturnsNullWhenTheExplicitConfigIsMissing(): void
    {
        $this->bindIo([
            '--config' => 'no-such-config.neon',
        ]);

        self::assertNull($this->invoke('resolveConfigFile'));
    }

    public function testResolveConfigFileFallsBackToAProjectConfig(): void
    {
        // With no --config, the usual project config names are tried against the
        // working directory; the repo's own phpstan.neon is found.
        $this->bindIo();

        $resolved = $this->invoke('resolveConfigFile');

        self::assertIsString($resolved);
        self::assertStringEndsWith('/phpstan.neon', $resolved);
    }

    public function testExplicitScanPathsKeepsOnlyExistingPaths(): void
    {
        $this->bindIo([
            '--path' => ['src', 'does-not-exist'],
        ]);

        $paths = $this->invoke('explicitScanPaths');

        self::assertIsArray($paths);
        self::assertCount(1, $paths);
        self::assertIsString($paths[0]);
        self::assertStringEndsWith('/src', $paths[0]);
    }

    public function testExplicitScanPathsIsNullWithoutThePathOption(): void
    {
        $this->bindIo();

        self::assertNull($this->invoke('explicitScanPaths'));
    }

    public function testParsePayloadsReportsUnparsableOutput(): void
    {
        $bufferedOutput = $this->bindIo();

        $payloads = $this->invoke('parsePayloads', 'not json at all', 'the stderr tail');

        self::assertNull($payloads);
        self::assertStringContainsString('did not return analysable output', $bufferedOutput->fetch());
    }

    /**
     * @param array<string, string> $variables
     * @return array{template: string, view: string, variables: array<string, string>}
     */
    private function payload(string $template, array $variables = [
        'title' => 'string',
    ]): array
    {
        return [
            'template' => $template,
            'view' => 'fixture',
            'variables' => $variables,
        ];
    }

    private function writeTemplate(string $name, string $contents): string
    {
        $path = $this->tmpDir . '/' . $name;
        file_put_contents($path, $contents);

        return $path;
    }

    private function invoke(string $method, mixed ...$args): mixed
    {
        $reflectionMethod = new ReflectionMethod($this->generateBladeSignaturesCommand, $method);

        return $reflectionMethod->invoke($this->generateBladeSignaturesCommand, ...$args);
    }

    /**
     * Give the command a real input (so `$this->option()` resolves) and a
     * buffered output (so `$this->error()`/`warn()`/`line()` have somewhere to
     * go), matching what Artisan wires up before handle() runs.
     *
     * @param array<string, string|list<string>> $parameters
     */
    private function bindIo(array $parameters = []): BufferedOutput
    {
        $arrayInput = new ArrayInput($parameters, $this->generateBladeSignaturesCommand->getDefinition());
        $bufferedOutput = new BufferedOutput();

        $inputProperty = new ReflectionProperty($this->generateBladeSignaturesCommand, 'input');
        $inputProperty->setValue($this->generateBladeSignaturesCommand, $arrayInput);

        $outputProperty = new ReflectionProperty($this->generateBladeSignaturesCommand, 'output');
        $outputProperty->setValue($this->generateBladeSignaturesCommand, new OutputStyle($arrayInput, $bufferedOutput));

        return $bufferedOutput;
    }
}
