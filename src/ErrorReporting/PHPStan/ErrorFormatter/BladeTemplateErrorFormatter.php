<?php

declare(strict_types=1);

namespace Bladestan\ErrorReporting\PHPStan\ErrorFormatter;

use PHPStan\Analyser\Error;
use PHPStan\Command\AnalyseCommand;
use PHPStan\Command\AnalysisResult;
use PHPStan\Command\ErrorFormatter\CiDetectedErrorFormatter;
use PHPStan\Command\ErrorFormatter\ErrorFormatter;
use PHPStan\Command\Output;
use PHPStan\File\RelativePathHelper;
use PHPStan\File\SimpleRelativePathHelper;
use Symfony\Component\Console\Formatter\OutputFormatter;
use function array_map;
use function count;
use function explode;
use function getenv;
use function is_string;
use function ltrim;
use function sprintf;
use function str_contains;
use function str_replace;

final class BladeTemplateErrorFormatter implements ErrorFormatter
{
    public function __construct(
        private RelativePathHelper $relativePathHelper,
        private readonly SimpleRelativePathHelper $simpleRelativePathHelper,
        private readonly CiDetectedErrorFormatter $ciDetectedErrorFormatter,
        private bool $showTipsOfTheDay,
        private ?string $editorUrl,
        private ?string $editorUrlTitle,
    ) {
    }

    /**
     * @api
     */
    public function formatErrors(AnalysisResult $analysisResult, Output $output): int
    {
        $this->ciDetectedErrorFormatter->formatErrors($analysisResult, $output);
        $projectConfigFile = 'phpstan.neon';
        if ($analysisResult->getProjectConfigFile() !== null) {
            $projectConfigFile = $this->relativePathHelper->getRelativePath($analysisResult->getProjectConfigFile());
        }

        $outputStyle = $output->getStyle();

        if (! $analysisResult->hasErrors() && ! $analysisResult->hasWarnings()) {
            $outputStyle->success('No errors');

            if ($this->showTipsOfTheDay && $analysisResult->isDefaultLevelUsed()) {
                $output->writeLineFormatted('💡 Tip of the Day:');
                $output->writeLineFormatted(sprintf(
                    "PHPStan is performing only the most basic checks.\nYou can pass a higher rule level through the <fg=cyan>--%s</> option\n(the default and current level is %d) to analyse code more thoroughly.",
                    /** @phpstan-ignore phpstanApi.classConstant */
                    AnalyseCommand::OPTION_LEVEL,
                    /** @phpstan-ignore phpstanApi.classConstant */
                    AnalyseCommand::DEFAULT_LEVEL,
                ));
                $output->writeLineFormatted('');
            }

            return 0;
        }

        /** @var array<string, Error[]> $fileErrors */
        $fileErrors = [];
        foreach ($analysisResult->getFileSpecificErrors() as $fileSpecificError) {
            if (! isset($fileErrors[$fileSpecificError->getFile()])) {
                $fileErrors[$fileSpecificError->getFile()] = [];
            }

            $fileErrors[$fileSpecificError->getFile()][] = $fileSpecificError;
        }

        foreach ($fileErrors as $file => $errors) {
            $rows = [];
            foreach ($errors as $error) {
                $message = $error->getMessage();
                $filePath = $error->getTraitFilePath() ?? $error->getFilePath();
                if ($error->getIdentifier() !== null && $error->canBeIgnored()) {
                    $message .= "\n";
                    $message .= '🪪  ' . $error->getIdentifier();
                }

                if ($error->getTip() !== null) {
                    $tip = $error->getTip();
                    $tip = str_replace('%configurationFile%', $projectConfigFile, $tip);

                    $message .= "\n";
                    if (str_contains($tip, "\n")) {
                        $lines = explode("\n", $tip);
                        foreach ($lines as $line) {
                            $message .= '💡 ' . ltrim($line, ' •') . "\n";
                        }
                    } else {
                        $message .= '💡 ' . $tip;
                    }
                }

                if (is_string($this->editorUrl)) {
                    $url = str_replace(
                        ['%file%', '%relFile%', '%line%'],
                        [
                            $filePath,
                            /** @phpstan-ignore phpstanApi.method */
                            $this->simpleRelativePathHelper->getRelativePath($filePath),
                            (string) $error->getLine(),
                        ],
                        $this->editorUrl,
                    );

                    if (is_string($this->editorUrlTitle)) {
                        $title = str_replace(
                            ['%file%', '%relFile%', '%line%'],
                            [
                                $filePath,
                                /** @phpstan-ignore phpstanApi.method */
                                $this->simpleRelativePathHelper->getRelativePath($filePath),
                                (string) $error->getLine(),
                            ],
                            $this->editorUrlTitle,
                        );
                    } else {
                        $title = $this->relativePathHelper->getRelativePath($filePath);
                    }

                    $message .= "\n✏️  <href=" . OutputFormatter::escape($url) . '>' . $title . '</>';
                }

                $rows[] = [$this->formatLineNumber($error->getLine()), $message];

                $errorMetadata = $error->getMetadata();
                $templateFilePath = $errorMetadata['template_file_path'] ?? null;
                $templateLine = $errorMetadata['template_line'] ?? null;

                if (is_string($templateFilePath) && is_int($templateLine)) {
                    /** @phpstan-ignore phpstanApi.method */
                    $relativeTemplateFileLine = $this->simpleRelativePathHelper->getRelativePath(
                        $templateFilePath
                    ) . ':' . $templateLine;

                    $rows[] = ['', 'rendered in: ' . $relativeTemplateFileLine];
                }
            }

            $outputStyle->table(['Line', $this->relativePathHelper->getRelativePath($file)], $rows);
        }

        if ($analysisResult->getNotFileSpecificErrors() !== []) {
            $outputStyle->table(
                ['', 'Error'],
                array_map(static fn (string $error): array => [
                    '',
                    OutputFormatter::escape($error),
                ], $analysisResult->getNotFileSpecificErrors())
            );
        }

        $warningsCount = count($analysisResult->getWarnings());
        if ($warningsCount > 0) {
            $outputStyle->table(
                ['', 'Warning'],
                array_map(static fn (string $warning): array => [
                    '',
                    OutputFormatter::escape($warning),
                ], $analysisResult->getWarnings())
            );
        }

        $finalMessage = sprintf(
            $analysisResult->getTotalErrorsCount() === 1 ? 'Found %d error' : 'Found %d errors',
            $analysisResult->getTotalErrorsCount()
        );
        if ($warningsCount > 0) {
            $finalMessage .= sprintf($warningsCount === 1 ? ' and %d warning' : ' and %d warnings', $warningsCount);
        }

        if ($analysisResult->getTotalErrorsCount() > 0) {
            $outputStyle->error($finalMessage);
        } else {
            $outputStyle->warning($finalMessage);
        }

        return $analysisResult->getTotalErrorsCount() > 0 ? 1 : 0;
    }

    private function formatLineNumber(?int $lineNumber): string
    {
        if ($lineNumber === null) {
            return '';
        }

        $isRunningInVSCodeTerminal = getenv('TERM_PROGRAM') === 'vscode';
        if ($isRunningInVSCodeTerminal) {
            return ':' . $lineNumber;
        }

        return (string) $lineNumber;
    }
}
