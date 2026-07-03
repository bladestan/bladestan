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
 * Returns both file paths and resolved view names, enabling the bootstrap
 * compiler to process each template and the call-site rule to resolve
 * view names back to file paths.
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
            $this->discoverInPath($path, null, $map);
        }

        // Namespaced (hinted) view paths
        /** @var array<string, array<string>> $hints */
        $hints = $finder->getHints();
        foreach ($hints as $namespace => $paths) {
            foreach ($paths as $path) {
                $this->discoverInPath($path, $namespace, $map);
            }
        }

        $this->templateMap = $map;

        return $map;
    }

    /**
     * Get the absolute file path for a given view name.
     *
     * @return string|null The absolute file path, or null if not found
     * @throws UnexpectedValueException
     */
    public function resolveFilePath(string $viewName): ?string
    {
        $map = $this->discoverTemplates();
        $flipped = array_flip($map);

        return $flipped[$viewName] ?? null;
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
     * Get the view name for a given absolute file path.
     *
     * @return string|null The view name, or null if not a discovered template
     * @throws UnexpectedValueException
     */
    public function resolveViewName(string $absoluteFilePath): ?string
    {
        $map = $this->discoverTemplates();
        $normalized = realpath($absoluteFilePath) ?: $absoluteFilePath;

        return $map[$normalized] ?? null;
    }

    /**
     * Clear the cached template map, forcing re-discovery on next call.
     */
    public function clearCache(): void
    {
        $this->templateMap = null;
    }

    /**
     * @throws \UnexpectedValueException
     */
    /**
     * @param array<string, string> $map
     * @throws UnexpectedValueException
     */
    private function discoverInPath(string $path, ?string $namespace, array &$map): void
    {
        if (! is_dir($path)) {
            return;
        }

        $realPath = realpath($path);
        if ($realPath === false) {
            return;
        }

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
    }
}
