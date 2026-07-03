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
    /**
     * Regex to extract the @bladestan-source header from a compiled PHP file.
     *
     * @see https://regex101.com/r/bK9xQ1/1
     */
    private const BLADESTAN_SOURCE_REGEX = '/^\/\/\s*@bladestan-source:\s*(.+)$/m';

    /**
     * Regex to extract bladestan-line mapping comments from compiled PHP.
     * These are the existing `/** file: X, line: Y * /` comments from
     * FileNameAndLineNumberAddingPreCompiler.
     */
    private const LINE_MAP_COMMENT_REGEX = '/^\/\*\*\s*file:\s*(.+?),\s*line:\s*(\d+)\s*\*\/$/m';

    /**
     * Cache of compiled file path → source blade file path.
     *
     * @var array<string, string|null>
     */
    private array $sourcePathCache = [];

    /**
     * Cache of compiled file path → line mapping (compiled line → [relativeBladeFile => bladeLine]).
     *
     * @var array<string, array<int, array<string, int>>>
     */
    private array $lineMappingCache = [];

    public function __construct(
        private RelativePathHelper $relativePathHelper,
        private readonly SimpleRelativePathHelper $simpleRelativePathHelper,
        private readonly CiDetectedErrorFormatter $ciDetectedErrorFormatter,
        private bool $showTipsOfTheDay,
        private ?string $editorUrl,
        private ?string $editorUrlTitle,
        private readonly string $compiledViewPath,
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

        /** @var array<string, list<Error>> $fileErrors */
        $fileErrors = [];
        foreach ($analysisResult->getFileSpecificErrors() as $fileSpecificError) {
            $displayFile = $this->resolveDisplayFile($fileSpecificError);

            if (! isset($fileErrors[$displayFile])) {
                $fileErrors[$displayFile] = [];
            }

            $fileErrors[$displayFile][] = $fileSpecificError;
        }

        foreach ($fileErrors as $file => $errors) {
            $rows = [];
            foreach ($errors as $error) {
                $message = $error->getMessage();
                $filePath = $error->getTraitFilePath() ?? $error->getFilePath();

                // Determine the display line — remap if this is a compiled blade file
                $displayLine = $this->resolveDisplayLine($error);

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

                // Determine the file path to use for editor URLs
                $editorFilePath = $filePath;
                $sourceBladeFile = $this->resolveSourceBladePath($filePath);
                if ($sourceBladeFile !== null) {
                    $editorFilePath = $sourceBladeFile;
                }

                if (is_string($this->editorUrl)) {
                    $url = str_replace(
                        ['%file%', '%relFile%', '%line%'],
                        [
                            $editorFilePath,
                            /** @phpstan-ignore phpstanApi.method */
                            $this->simpleRelativePathHelper->getRelativePath($editorFilePath),
                            (string) $displayLine,
                        ],
                        $this->editorUrl,
                    );

                    if (is_string($this->editorUrlTitle)) {
                        $title = str_replace(
                            ['%file%', '%relFile%', '%line%'],
                            [
                                $editorFilePath,
                                /** @phpstan-ignore phpstanApi.method */
                                $this->simpleRelativePathHelper->getRelativePath($editorFilePath),
                                (string) $displayLine,
                            ],
                            $this->editorUrlTitle,
                        );
                    } else {
                        $title = $this->relativePathHelper->getRelativePath($editorFilePath);
                    }

                    $message .= "\n✏️  <href=" . OutputFormatter::escape($url) . '>' . $title . '</>';
                }

                $rows[] = [$this->formatLineNumber($displayLine), $message];

                // Legacy call-site-centric metadata (from BladeRule)
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

    /**
     * Determine the display file path for an error.
     *
     * If the error comes from a compiled blade file (in the compiledViewPath),
     * remap it to the original blade file path. Otherwise, use the file path as-is.
     */
    private function resolveDisplayFile(Error $error): string
    {
        $filePath = $error->getFile();

        $sourceBladePath = $this->resolveSourceBladePath($filePath);
        if ($sourceBladePath !== null) {
            return $sourceBladePath;
        }

        return $filePath;
    }

    /**
     * Determine the display line number for an error.
     *
     * If the error comes from a compiled blade file, attempt to remap the line
     * number using the `/** file: X, line: Y * /` comments in the compiled PHP.
     */
    private function resolveDisplayLine(Error $error): ?int
    {
        $filePath = $error->getFile();
        $compiledLine = $error->getLine();

        if ($compiledLine === null) {
            return null;
        }

        // Only remap for compiled blade files
        if (! $this->isCompiledBladeFile($filePath)) {
            return $compiledLine;
        }

        $lineMapping = $this->getLineMapping($filePath);
        if ($lineMapping === []) {
            return $compiledLine;
        }

        // Find the mapping entry for this compiled line (exact or nearest preceding)
        if (isset($lineMapping[$compiledLine])) {
            $entry = $lineMapping[$compiledLine];
            return (int) current($entry);
        }

        // Find the nearest preceding mapped line
        $nearestBladeLine = null;
        foreach ($lineMapping as $mappedCompiledLine => $entry) {
            if ($mappedCompiledLine > $compiledLine) {
                break;
            }

            $nearestBladeLine = (int) current($entry);
        }

        return $nearestBladeLine ?? $compiledLine;
    }

    /**
     * Check if a file path is a compiled blade file in the compiledViewPath directory.
     */
    private function isCompiledBladeFile(string $filePath): bool
    {
        $normalizedCompiled = rtrim($this->compiledViewPath, '/\\') . DIRECTORY_SEPARATOR;
        $normalizedFile = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $filePath);

        return str_starts_with($normalizedFile, $normalizedCompiled);
    }

    /**
     * Resolve the source blade file path from a compiled PHP file.
     *
     * Reads the `// @bladestan-source: /path/to/file.blade.php` header comment
     * from the compiled file.
     */
    private function resolveSourceBladePath(string $compiledFilePath): ?string
    {
        if (! $this->isCompiledBladeFile($compiledFilePath)) {
            return null;
        }

        if (array_key_exists($compiledFilePath, $this->sourcePathCache)) {
            return $this->sourcePathCache[$compiledFilePath];
        }

        $contents = @file_get_contents($compiledFilePath);
        if ($contents === false) {
            $this->sourcePathCache[$compiledFilePath] = null;
            return null;
        }

        // Only read the first few lines — the header is at the top
        $header = substr($contents, 0, 512);

        if (preg_match(self::BLADESTAN_SOURCE_REGEX, $header, $matches) !== 1) {
            $this->sourcePathCache[$compiledFilePath] = null;
            return null;
        }

        $sourcePath = trim($matches[1]);
        $this->sourcePathCache[$compiledFilePath] = $sourcePath;

        return $sourcePath;
    }

    /**
     * Get the line mapping for a compiled blade file.
     *
     * Parses the `/** file: X, line: Y * /` comments to build a mapping from
     * compiled PHP line numbers to original blade file line numbers.
     *
     * @return array<int, array<string, int>> compiledLine => [bladeFile => bladeLine]
     */
    private function getLineMapping(string $compiledFilePath): array
    {
        if (isset($this->lineMappingCache[$compiledFilePath])) {
            return $this->lineMappingCache[$compiledFilePath];
        }

        $contents = @file_get_contents($compiledFilePath);
        if ($contents === false) {
            $this->lineMappingCache[$compiledFilePath] = [];
            return [];
        }

        $mapping = [];
        $lines = explode("\n", $contents);

        foreach ($lines as $lineIndex => $lineContent) {
            $phpLineNumber = $lineIndex + 1;

            if (preg_match(self::LINE_MAP_COMMENT_REGEX, trim($lineContent), $matches) === 1) {
                $bladeFile = $matches[1];
                $bladeLine = (int) $matches[2];

                // The comment is on the line before the actual code, so map
                // both the comment line and the next line to the blade location
                $mapping[$phpLineNumber] = [
                    $bladeFile => $bladeLine,
                ];
                $mapping[$phpLineNumber + 1] = [
                    $bladeFile => $bladeLine,
                ];
            }
        }

        ksort($mapping);

        $this->lineMappingCache[$compiledFilePath] = $mapping;

        return $mapping;
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
