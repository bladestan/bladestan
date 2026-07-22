<?php

declare(strict_types=1);

namespace Bladestan\Discovery;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\View\FileViewFinder;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RecursiveRegexIterator;
use RegexIterator;
use SplFileInfo;
use UnexpectedValueException;

/**
 * Discovers all blade templates in the project using Laravel's ViewFinder.
 *
 * Returns a mapping of absolute file path to view name, which the bootstrap
 * compiler walks to compile every template and the signature generator reuses
 * to find templates to annotate.
 */
final class TemplateDiscovery
{
    /**
     * Cached mapping of absolute file path → view name.
     *
     * @var array<string, string>|null
     */
    private ?array $templateMap = null;

    /**
     * Discover all blade templates and return a mapping of absolute file path → view name.
     *
     * @return array<string, string> absoluteFilePath => viewName
     * @throws UnexpectedValueException
     */
    public function discoverTemplates(): array
    {
        if (is_array($this->templateMap)) {
            return $this->templateMap;
        }

        $finder = resolve(ViewFactory::class)->getFinder();
        assert($finder instanceof FileViewFinder);

        $map = [];

        // Default (non-namespaced) view paths
        foreach ($finder->getPaths() as $path) {
            $map = [...$map, ...$this->discoverInPath($path, null)];
        }

        // Namespaced (hinted) view paths
        /** @var array<string, array<string>> $hints */
        $hints = $finder->getHints();
        foreach ($hints as $namespace => $paths) {
            foreach ($paths as $path) {
                $map = [...$map, ...$this->discoverInPath($path, $namespace)];
            }
        }

        $this->templateMap = $map;

        return $map;
    }

    /**
     * Get all discovered absolute file paths.
     *
     * @return list<string>
     * @throws UnexpectedValueException
     */
    public function getFilePaths(): array
    {
        return array_keys($this->discoverTemplates());
    }

    /**
     * @return array<string, string> absoluteFilePath => viewName
     * @throws UnexpectedValueException
     */
    private function discoverInPath(string $path, ?string $namespace): array
    {
        if (! is_dir($path)) {
            return [];
        }

        $realPath = realpath($path);
        if ($realPath === false) {
            return [];
        }

        $map = [];

        $directory = new RecursiveDirectoryIterator($realPath);
        $iterator = new RecursiveIteratorIterator($directory);
        $regex = new RegexIterator($iterator, '/\.blade\.php$/', RecursiveRegexIterator::MATCH);

        /** @var SplFileInfo $fileInfo */
        foreach ($regex as $fileInfo) {
            $absolutePath = $fileInfo->getPathname();

            // Convert absolute path to a relative view name:
            // /path/to/views/welcome.blade.php → welcome
            // /path/to/views/layouts/app.blade.php → layouts.app
            $relativePath = substr($absolutePath, strlen($realPath) + 1);
            $viewName = str_replace([DIRECTORY_SEPARATOR, '.blade.php'], ['.', ''], $relativePath);

            if ($namespace !== null) {
                $viewName = $namespace . '::' . $viewName;
            }

            $map[$absolutePath] = $viewName;
        }

        return $map;
    }
}
