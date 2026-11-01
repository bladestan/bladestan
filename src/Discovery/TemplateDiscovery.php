<?php

declare(strict_types=1);

namespace Bladestan\Discovery;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\View\FileViewFinder;
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
     * The project's own view directories, namespaced hints included.
     *
     * These are the directories a user puts in PHPStan's `paths` to have their
     * templates analysed, so callers can name a missing one rather than
     * describing it. Directories under `vendor/` are left out: a package's
     * templates cannot be annotated with a signature and are never compiled, so
     * they are not something to analyse or to advise adding.
     *
     * @return list<string> Absolute real paths, deduplicated
     */
    public function getViewRoots(): array
    {
        $finder = resolve(ViewFactory::class)->getFinder();
        assert($finder instanceof FileViewFinder);

        /** @var array<array<string>> $hints */
        $hints = $finder->getHints();

        $roots = [];
        foreach ([...$finder->getPaths(), ...array_merge(...array_values($hints) ?: [[]])] as $path) {
            $realPath = realpath((string) $path);
            if ($realPath === false) {
                continue;
            }

            if (str_contains(str_replace('\\', '/', $realPath), '/vendor/')) {
                continue;
            }

            $roots[$realPath] = true;
        }

        return array_keys($roots);
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

        /** @var SplFileInfo $fileInfo */
        foreach (BladeFileIterator::over($realPath) as $fileInfo) {
            $absolutePath = $fileInfo->getPathname();

            $relativePath = substr($absolutePath, strlen($realPath) + 1);
            $viewName = $this->viewNameFromRelativePath($relativePath);

            if ($namespace !== null) {
                $viewName = $namespace . '::' . $viewName;
            }

            $map[$absolutePath] = $viewName;
        }

        return $map;
    }

    /**
     * Derive a Laravel view name from a template's path relative to its view
     * root:
     *   'welcome.blade.php'      → 'welcome'
     *   'layouts/app.blade.php'  → 'layouts.app'
     *
     * Only the trailing `.blade.php` suffix is stripped and the dots come from
     * directory separators alone. A blanket str_replace of both would also
     * strip a `.blade.php` occurring mid-name and turn a literal dot in a
     * filename into a separator, so `foo.bar.blade.php` and `foo/bar.blade.php`
     * would collapse to the same name and the first discovered would shadow the
     * other. Laravel resolves a view name by turning dots into slashes, so only
     * `foo/bar.blade.php` is ever reachable as `foo.bar`; deriving the name the
     * same way keeps the reachable template from being shadowed.
     */
    private function viewNameFromRelativePath(string $relativePath): string
    {
        $withoutSuffix = substr($relativePath, 0, -strlen('.blade.php'));

        return str_replace(DIRECTORY_SEPARATOR, '.', $withoutSuffix);
    }
}
