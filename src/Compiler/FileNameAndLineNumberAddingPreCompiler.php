<?php

declare(strict_types=1);

namespace Bladestan\Compiler;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\View\FileViewFinder;

final class FileNameAndLineNumberAddingPreCompiler
{
    /**
     * @var string
     */
    private const PHP_SINGLE_LINE_COMMENT_REGEX = '#^/\*\*.*?\*/$#';

    /**
     * @see https://regex101.com/r/SfpjMO/1
     * @var string
     */
    private const PHP_PARTIAL_COMMENT = '#^(\* )?@(var|param|method|extends|implements|template) +(.*?) \$[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*#';

    /**
     * @see https://regex101.com/r/rJXmfO/1
     * @var string
     */
    private const START_OF_MULTILINE_COMPONENT = '/^.*<(?:livewire:|x-)\S+[^>](?:\s+[^>]+?="[^"]*?")*[^>]*$/';

    /**
     * @see https://regex101.com/r/IoNxTs/1
     * @var string
     */
    private const END_OF_MULTILINE_COMPONENT = '/^(?:\s+[^>]+?="[^"]*?")*\s*\/?>/';

    /**
     * @var list<string>
     */
    private readonly array $paths;

    public function __construct()
    {
        $finder = resolve(ViewFactory::class)->getFinder();
        assert($finder instanceof FileViewFinder);
        /** @var array<array<string>> */
        $hints = $finder->getHints();
        /** @var array<string> */
        $rawPaths = array_merge($finder->getPaths(), ...array_values($hints));
        $rawPaths[] = base_path();

        $paths = [];
        foreach ($rawPaths as $rawPath) {
            if ($realPath = realpath($rawPath)) {
                $paths[] = $realPath . DIRECTORY_SEPARATOR;
            }
        }

        $paths = array_unique($paths);
        usort($paths, fn ($a, $b): int => strlen($b) <=> strlen($a));

        $this->paths = $paths;
    }

    public function getRelativePath(string $fileName): string
    {
        $fileName = realpath($fileName) ?: $fileName;
        foreach ($this->paths as $path) {
            if (str_starts_with($fileName, $path)) {
                return substr($fileName, strlen($path));
            }
        }

        return $fileName;
    }

    public function completeLineCommentsToBladeContents(string $fileName, string $fileContents): string
    {
        $fileName = $this->getRelativePath($fileName);
        $lines = explode(PHP_EOL, $fileContents);

        $insideComponentTag = false;
        $lineNumber = 1;

        foreach ($lines as $key => $line) {
            if (! $insideComponentTag && ! $this->shouldSkip($line)) {
                $lines[$key] = "/** file: {$fileName}, line: {$lineNumber} */{$line}";
            }

            if (! $insideComponentTag) {
                $insideComponentTag = preg_match(self::START_OF_MULTILINE_COMPONENT, $line) === 1;
            } else {
                $insideComponentTag = preg_match(self::END_OF_MULTILINE_COMPONENT, $line) !== 1;
            }

            ++$lineNumber;
        }

        return implode(PHP_EOL, $lines);
    }

    private function shouldSkip(string $line): bool
    {
        if (in_array(trim($line), ['', '/**', '*/'], true)) {
            return true;
        }

        if (preg_match(self::PHP_SINGLE_LINE_COMMENT_REGEX, trim($line))) {
            return true;
        }

        return preg_match(self::PHP_PARTIAL_COMMENT, trim($line)) === 1;
    }
}
