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
        $this->assertStringNotContainsString('welcome-compiled.php', $content);
        // The line is remapped from the compiled 6 to the template's 5,
        // reported on the same row as the message.
        $this->assertMatchesRegularExpression('/\b5\b\s+Cannot pass string to strlen/', $content);
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
