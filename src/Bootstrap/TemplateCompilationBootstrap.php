<?php

declare(strict_types=1);

namespace Bladestan\Bootstrap;

use Bladestan\Compiler\BladeToPHPCompiler;
use Bladestan\Discovery\TemplateDiscovery;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;
use UnexpectedValueException;

/**
 * Compiles all blade templates to standalone PHP files before analysis starts.
 *
 * Runs from PHPStan's bootstrap (Phase 1 of the template-centric architecture).
 * PHPStan executes bootstrap files in EVERY process — including parallel
 * workers — so this class must never be invoked from a worker (see
 * bootstrap.php) and must be safe to re-run: compilation is incremental via a
 * manifest, files are written atomically, and orphans are pruned instead of
 * wiping the directory.
 */
final class TemplateCompilationBootstrap
{
    private const MANIFEST_FILE = 'bladestan-manifest.json';

    /**
     * All compiled templates live under this segment. Templates keep their view
     * hierarchy for readability, but a view named `app.*` would otherwise
     * compile straight to `.bladestan/app/…`, where a user's path-scoped rule
     * (for example one that forbids `echo` under `app/`) would flag generated
     * code full of echoes. Nesting everything under one clearly generated
     * segment keeps the compiled tree from impersonating a source directory and
     * gives rule authors a single path to exclude.
     */
    private const OUTPUT_ROOT = '__templates__';

    public function __construct(
        private readonly TemplateDiscovery $templateDiscovery,
        private readonly BladeToPHPCompiler $bladeToPHPCompiler,
        private readonly string $compiledViewPath,
        private readonly string $projectRoot,
    ) {
    }

    public function run(): void
    {
        if (! is_dir($this->compiledViewPath)) {
            mkdir($this->compiledViewPath, 0777, true);
        }

        try {
            $templates = $this->templateDiscovery->discoverTemplates();
        } catch (Throwable) {
            return;
        }

        $contextHash = $this->contextHash();
        $manifest = $this->loadManifest();

        // A manifest from another project or a stale compilation context means
        // none of the existing output can be trusted — start over. The default
        // %tmpDir% is shared machine-wide, so another project's compiled files
        // must never survive into this project's analysis paths.
        if ($manifest === null
            || $manifest['projectRoot'] !== $this->projectRoot
            || $manifest['contextHash'] !== $contextHash
        ) {
            $this->removeAllCompiledFiles();
            $manifest = [
                'projectRoot' => $this->projectRoot,
                'contextHash' => $contextHash,
                'templates' => [],
            ];
        }

        $newEntries = [];
        $seenViewNames = [];

        $vendorPrefix = $this->projectRoot . '/vendor/';

        foreach ($templates as $filePath => $viewName) {
            // Third-party templates can't be annotated with signatures and
            // their errors aren't actionable — analyze project templates only.
            // Call-site validation against vendor templates is unaffected: it
            // reads the blade sources directly.
            if (str_starts_with($filePath, $vendorPrefix)) {
                continue;
            }

            // Laravel resolves duplicate view names to the first registered
            // path; later duplicates are unreachable and must not clobber it.
            if (isset($seenViewNames[$viewName])) {
                continue;
            }

            $seenViewNames[$viewName] = true;

            $sourceContents = @file_get_contents($filePath);
            if ($sourceContents === false) {
                continue;
            }

            $sourceHash = hash('xxh128', $sourceContents);
            $relativeOutputPath = $this->relativeOutputPath($viewName);
            $outputPath = $this->compiledViewPath . '/' . $relativeOutputPath;

            $existingEntry = $manifest['templates'][$viewName] ?? null;
            if ($existingEntry !== null
                && $existingEntry['sourceHash'] === $sourceHash
                && is_file($outputPath)
            ) {
                $newEntries[$viewName] = $existingEntry;
                continue;
            }

            try {
                /** @throws Throwable */
                $result = $this->bladeToPHPCompiler->compileStandalone($filePath, $viewName);
            } catch (Throwable) {
                // Uncompilable template: make sure no stale output survives.
                if (is_file($outputPath)) {
                    @unlink($outputPath);
                }

                continue;
            }

            $this->writeAtomically($outputPath, $result->phpFileContents);

            $newEntries[$viewName] = [
                'source' => $filePath,
                'sourceHash' => $sourceHash,
                'output' => $relativeOutputPath,
            ];
        }

        // Prune outputs for templates that no longer exist.
        foreach ($manifest['templates'] as $viewName => $entry) {
            if (isset($newEntries[$viewName])) {
                continue;
            }

            $orphanPath = $this->compiledViewPath . '/' . $entry['output'];
            if (is_file($orphanPath)) {
                @unlink($orphanPath);
            }
        }

        $manifest['templates'] = $newEntries;
        $this->writeAtomically(
            $this->compiledViewPath . '/' . self::MANIFEST_FILE,
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
        );
    }

    /**
     * The context hash guards whether existing compiled output can be trusted.
     * It combines the compiler's own factors (output-format version, Laravel
     * version, shared variables) with a hash of the installed dependencies:
     * a `composer update` can change compiled output even when no template
     * changed (most directly through `illuminate/view`, whose Blade compiler
     * produces the PHP we analyze). PHPStan invalidates its own result cache on
     * a `composer.lock` change; mirroring that here forces a full recompile so
     * stale output from an older Blade compiler never survives.
     */
    private function contextHash(): string
    {
        $lockContents = @file_get_contents($this->projectRoot . '/composer.lock');
        $dependencyHash = $lockContents === false ? '' : hash('xxh128', $lockContents);

        return hash('xxh128', $this->bladeToPHPCompiler->getCompilationContextHash() . "\0" . $dependencyHash);
    }

    /**
     * Build a path-safe output filename that preserves the view hierarchy under
     * the generated-output root:
     *   'welcome'         → '__templates__/welcome.php'
     *   'layouts.app'     → '__templates__/layouts/app.php'
     *   'Test::some.view' → '__templates__/__ns__Test/some/view.php'
     */
    private function relativeOutputPath(string $viewName): string
    {
        if (str_contains($viewName, '::')) {
            [$namespace, $name] = explode('::', $viewName, 2);

            return self::OUTPUT_ROOT . '/__ns__' . $namespace . '/' . str_replace('.', '/', $name) . '.php';
        }

        return self::OUTPUT_ROOT . '/' . str_replace('.', '/', $viewName) . '.php';
    }

    /**
     * @return array{projectRoot: string, contextHash: string, templates: array<string, array{source: string, sourceHash: string, output: string}>}|null
     */
    private function loadManifest(): ?array
    {
        $manifestPath = $this->compiledViewPath . '/' . self::MANIFEST_FILE;
        if (! is_file($manifestPath)) {
            return null;
        }

        $contents = @file_get_contents($manifestPath);
        if ($contents === false) {
            return null;
        }

        $manifest = json_decode($contents, true);
        if (! is_array($manifest)
            || ! is_string($manifest['projectRoot'] ?? null)
            || ! is_string($manifest['contextHash'] ?? null)
            || ! is_array($manifest['templates'] ?? null)
        ) {
            return null;
        }

        /** @var array{projectRoot: string, contextHash: string, templates: array<string, array{source: string, sourceHash: string, output: string}>} $manifest */
        return $manifest;
    }

    /**
     * Remove only what Bladestan owns: the generated-output root and the
     * manifest. The wipe must never touch anything else, because a
     * misconfigured `compiledViewPath` can point at a directory with user
     * files in it, and the missing manifest that triggers this wipe is
     * exactly what a first run against such a directory looks like.
     */
    private function removeAllCompiledFiles(): void
    {
        @unlink($this->compiledViewPath . '/' . self::MANIFEST_FILE);

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $this->compiledViewPath . '/' . self::OUTPUT_ROOT,
                    FilesystemIterator::SKIP_DOTS
                ),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
        } catch (UnexpectedValueException) {
            return;
        }

        /** @var SplFileInfo $fileInfo */
        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isDir()) {
                @rmdir($fileInfo->getPathname());
            } else {
                @unlink($fileInfo->getPathname());
            }
        }
    }

    /**
     * Write via temp file + rename so a partially written file is never
     * observable by a concurrently running PHPStan process.
     */
    private function writeAtomically(string $path, string $contents): void
    {
        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $tempPath = $path . '.' . getmypid() . '.tmp';
        if (file_put_contents($tempPath, $contents) === false) {
            return;
        }

        rename($tempPath, $path);
    }
}
