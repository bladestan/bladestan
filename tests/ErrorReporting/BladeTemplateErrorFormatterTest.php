<?php

declare(strict_types=1);

namespace Bladestan\Tests\ErrorReporting;

use Bladestan\ErrorReporting\PHPStan\ErrorFormatter\BladeTemplateErrorFormatter;
use PHPStan\Analyser\Error;
use PHPStan\Command\AnalysisResult;
use PHPStan\Command\ErrorFormatter\CiDetectedErrorFormatter;
use PHPStan\File\SimpleRelativePathHelper;
use PHPStan\Testing\ErrorFormatterTestCase;

final class BladeTemplateErrorFormatterTest extends ErrorFormatterTestCase
{
    private const COMPILED_DIR = __DIR__ . '/Fixture';

    public function testRemapsCompiledFileAndLineToTemplate(): void
    {
        $compiledFile = self::COMPILED_DIR . '/welcome-compiled.php';

        // Compiled line 6 (echo strlen) maps to blade line 5 via the
        // `file: …, line: 5` comment on the preceding line.
        $analysisResult = $this->analysisResult([new Error('Cannot pass string to strlen', $compiledFile, 6)]);

        $exitCode = $this->createFormatter()
            ->formatErrors($analysisResult, $this->getOutput());

        $content = $this->getOutputContent();

        $this->assertSame(1, $exitCode);
        // The error points at the original template, not the compiled PHP.
        $this->assertStringContainsString('welcome.blade.php', $content);
        // The compiled path never leaks into the rendered table. (Under a CI
        // provider the delegated CiDetectedErrorFormatter still prints an
        // annotation line with the raw path — a known limitation, see the
        // formatter — so the assertion is scoped to the table.)
        $this->assertStringNotContainsString('welcome-compiled.php', $this->tableOnly($content));
        // The line is remapped from the compiled 6 to the template's 5,
        // reported on the same row as the message.
        $this->assertMatchesRegularExpression('/\b5\b\s+Cannot pass string to strlen/', $content);
    }

    /**
     * Bladestan's own table output with any CI-provider annotation lines
     * (GitHub `::error …`, TeamCity `##teamcity[…]`) removed.
     */
    private function tableOnly(string $content): string
    {
        $lines = array_filter(
            explode("\n", $content),
            static fn (string $line): bool => ! str_starts_with(ltrim($line), '::')
                && ! str_contains($line, '##teamcity'),
        );

        return implode("\n", $lines);
    }

    public function testEmitsEditorLinkPointingAtTheTemplate(): void
    {
        $compiledFile = self::COMPILED_DIR . '/welcome-compiled.php';

        $analysisResult = $this->analysisResult([new Error('Cannot pass string to strlen', $compiledFile, 6)]);

        $this->createFormatter('editor://open?file=%file%&line=%line%', 'Open')
            ->formatErrors($analysisResult, $this->getOutput(true));

        $content = $this->getOutputContent(true);

        // The clickable link targets the template source and the remapped line.
        $this->assertStringContainsString('editor://open?file=/project/resources/views/welcome.blade.php&line=5', $content);
    }

    public function testHeaderRegionErrorsAnchorAtTemplateTop(): void
    {
        $compiledFile = self::COMPILED_DIR . '/welcome-compiled.php';

        // Compiled line 4 is the injected `/** @var string $title */` header —
        // it precedes every `file: …, line: …` marker, so it has no template
        // counterpart. Its raw compiled line would land on unrelated template
        // content, so it must anchor at the top of the template instead.
        $analysisResult = $this->analysisResult([
            new Error('PHPDoc tag @var contains unknown class App\\Renamed', $compiledFile, 4),
        ]);

        $this->createFormatter()
            ->formatErrors($analysisResult, $this->getOutput());

        $content = $this->getOutputContent();

        $this->assertStringContainsString('welcome.blade.php', $content);
        // Reported against the top of the template, not compiled line 4.
        $this->assertMatchesRegularExpression('/\b1\b\s+PHPDoc tag @var contains unknown class/', $content);
    }

    public function testStubWithNoLineMarkersAnchorsAtTemplateTop(): void
    {
        // A template that failed to compile leaves a comment-only stub with no
        // `file: …, line: …` markers at all. An error against it (line 3, the
        // @bladestan-error marker) has no line mapping, so it anchors at the top.
        $compiledFile = self::COMPILED_DIR . '/stub-compiled.php';

        $analysisResult = $this->analysisResult([
            new Error('View [broken.blade.php] contains syntax errors.', $compiledFile, 3),
        ]);

        $this->createFormatter()
            ->formatErrors($analysisResult, $this->getOutput());

        $content = $this->getOutputContent();

        $this->assertStringContainsString('broken.blade.php', $content);
        $this->assertMatchesRegularExpression('/\b1\b\s+View \[broken\.blade\.php\] contains syntax errors\./', $content);
    }

    public function testLeavesNonCompiledFilesUntouched(): void
    {
        $analysisResult = $this->analysisResult([new Error('Undefined variable', '/app/Http/Controller.php', 42)]);

        $this->createFormatter()
            ->formatErrors($analysisResult, $this->getOutput());

        $content = $this->getOutputContent();

        $this->assertStringContainsString('Controller.php', $content);
        $this->assertMatchesRegularExpression('/\b42\b\s+Undefined variable/', $content);
    }

    public function testReportsSuccessWhenThereAreNoErrors(): void
    {
        $analysisResult = $this->analysisResult([]);

        $exitCode = $this->createFormatter()
            ->formatErrors($analysisResult, $this->getOutput());

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('No errors', $this->getOutputContent());
    }

    /**
     * @return list<string>
     */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../../config/extension.neon'];
    }

    /**
     * @param list<Error> $fileErrors
     */
    private function analysisResult(array $fileErrors): AnalysisResult
    {
        return new AnalysisResult(
            $fileErrors,
            [],
            [],
            [],
            [],
            false,
            null,
            true,
            0,
            false,
            [],
        );
    }

    private function createFormatter(?string $editorUrl = null, ?string $editorUrlTitle = null): BladeTemplateErrorFormatter
    {
        $simpleRelativePathHelper = new SimpleRelativePathHelper((string) getcwd());

        return new BladeTemplateErrorFormatter(
            $simpleRelativePathHelper,
            $simpleRelativePathHelper,
            self::getContainer()->getByType(CiDetectedErrorFormatter::class),
            false,
            $editorUrl,
            $editorUrlTitle,
            (string) realpath(self::COMPILED_DIR),
        );
    }
}
