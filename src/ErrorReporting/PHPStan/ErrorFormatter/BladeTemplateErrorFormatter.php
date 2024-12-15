<?php

declare(strict_types=1);

namespace Bladestan\ErrorReporting\PHPStan\ErrorFormatter;

use PHPStan\Analyser\Error;
use PHPStan\Command\AnalysisResult;
use PHPStan\Command\ErrorFormatter\ErrorFormatter;
use PHPStan\Command\Output;
use PHPStan\File\RelativePathHelper;
use PHPStan\File\SimpleRelativePathHelper;
use Symfony\Component\Console\Command\Command;

final class BladeTemplateErrorFormatter implements ErrorFormatter
{
    private readonly SimpleRelativePathHelper $simpleRelativePathHelper;

    public function __construct(
        private RelativePathHelper $relativePathHelper,
    ) {
        $currentWorkingDirectory = getcwd();
        assert($currentWorkingDirectory !== false);
        /** @phpstan-ignore phpstanApi.constructor */
        $this->simpleRelativePathHelper = new SimpleRelativePathHelper($currentWorkingDirectory);
    }

    /**
     * @return Command::SUCCESS|Command::FAILURE
     */
    public function formatErrors(AnalysisResult $analysisResult, Output $output): int
    {
        $projectConfigFile = 'phpstan.neon';
        if ($analysisResult->getProjectConfigFile() !== null) {
            $projectConfigFile = $this->relativePathHelper->getRelativePath($analysisResult->getProjectConfigFile());
        }

        $outputStyle = $output->getStyle();

        if (! $analysisResult->hasErrors() && ! $analysisResult->hasWarnings()) {
            $outputStyle->success('No errors');
            return Command::SUCCESS;
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

            /** @var Error $error */
            foreach ($errors as $error) {
                $message = $error->getMessage();

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

                $rows[] = [(string) $error->getLine(), $message];

                $errorMetadata = $error->getMetadata();
                $templateFilePath = $errorMetadata['template_file_path'] ?? null;
                $templateLine = $errorMetadata['template_line'] ?? null;

                if ($templateFilePath && $templateLine) {
                    /** @phpstan-ignore phpstanApi.method */
                    $relativeTemplateFileLine = $this->simpleRelativePathHelper->getRelativePath(
                        $templateFilePath
                    ) . ':' . $templateLine;

                    $rows[] = ['', 'rendered in: ' . $relativeTemplateFileLine];
                }
            }

            /** @phpstan-ignore phpstanApi.method */
            $relativeFilePath = $this->simpleRelativePathHelper->getRelativePath($file);
            $outputStyle->table(['Line', $relativeFilePath], $rows);
        }

        if ($analysisResult->getNotFileSpecificErrors() !== []) {
            $outputStyle->table(
                ['', 'Error'],
                array_map(static fn (string $error): array => ['', $error], $analysisResult->getNotFileSpecificErrors())
            );
        }

        foreach ($analysisResult->getWarnings() as $warning) {
            $outputStyle->warning($warning);
        }

        $finalMessage = sprintf(
            $analysisResult->getTotalErrorsCount() === 1 ? 'Found %d error' : 'Found %d errors',
            $analysisResult->getTotalErrorsCount()
        );

        if ($analysisResult->getTotalErrorsCount() > 0) {
            $outputStyle->error($finalMessage);

            return Command::FAILURE;
        }

        $outputStyle->warning($finalMessage);

        return Command::SUCCESS;
    }
}
