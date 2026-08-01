<?php

declare(strict_types=1);

namespace Bladestan\Bootstrap;

use function basename;
use function realpath;
use function rtrim;
use function str_replace;
use function str_starts_with;
use function strrpos;
use function substr;

/**
 * Decides whether PHPStan is analysing Bladestan's compiled output.
 *
 * Compilation is opt-in: templates are built only when the compiled directory
 * is among the paths PHPStan analyses, which the user arranges by adding
 * `.bladestan` to `paths`. The same question is asked of two different path
 * lists, so it lives here rather than inline in the bootstrap: PHPStan's
 * `analysedPaths` describes what *this run* looks at (a run scoped to paths on
 * the command line replaces them), while `analysedPathsFromConfig` describes
 * how the *project* is configured. The first decides whether to compile, the
 * second whether the setup is worth warning about.
 *
 * @see \Bladestan\Tests\Bootstrap\CompiledPathDetectorTest
 */
final class CompiledPathDetector
{
    /**
     * Whether the compiled directory is an analysed path or nested inside one.
     *
     * PHPStan's file discovery walks into subdirectories, so `paths: [build]`
     * with `compiledViewPath: build/bladestan` analyses the compiled files just
     * as directly as listing them explicitly; requiring exact equality left that
     * output discovered but never refreshed, so it went permanently stale with
     * no warning.
     *
     * @param list<string> $analysedPaths
     */
    public function isAnalysed(string $compiledViewPath, array $analysedPaths): bool
    {
        $target = $this->canonicalize($compiledViewPath);

        foreach ($analysedPaths as $analysedPath) {
            $candidate = $this->canonicalize($analysedPath);
            if ($target === $candidate || str_starts_with($target, $candidate . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * An analysed path named like the compiled directory but resolving to a
     * different real directory, or null when there is none.
     *
     * This is the cwd-vs-config-dir divergence: the user added the compiled
     * directory to `paths` (which PHPStan absolutizes against the config file)
     * while compiledViewPath absolutized against the process working directory,
     * and the two are genuinely different directories. There is no `%configDir%`
     * neon variable to anchor both sides to, so the mismatch can only be
     * reported, not repaired. It is a divergence only when the compiled
     * directory is not analysed after all, which is why an analysed path here
     * always yields null.
     *
     * @param list<string> $analysedPaths
     */
    public function divergentPath(string $compiledViewPath, array $analysedPaths): ?string
    {
        if ($this->isAnalysed($compiledViewPath, $analysedPaths)) {
            return null;
        }

        $targetBasename = basename($this->canonicalize($compiledViewPath));

        foreach ($analysedPaths as $analysedPath) {
            $candidate = $this->canonicalize($analysedPath);
            if (basename($candidate) === $targetBasename) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Canonicalize a path so two spellings of the same location compare equal:
     * resolve `\` to `/`, then realpath the deepest ancestor that exists and
     * re-append the rest. The compiled directory may not exist on a first run,
     * so realpath cannot be applied to it whole; walking up to the nearest
     * existing parent still collapses symlinks and `.`/`..` segments (a
     * symlinked project root, or macOS `/var` -> `/private/var`) that a raw
     * string compare would miss.
     */
    private function canonicalize(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $suffix = '';
        $probe = $path;

        while ($probe !== '' && $probe !== '/') {
            $real = realpath($probe);
            if ($real !== false) {
                return rtrim(str_replace('\\', '/', $real), '/') . $suffix;
            }

            $slash = strrpos($probe, '/');
            if ($slash === false || $slash === 0) {
                break;
            }

            $suffix = substr($probe, $slash) . $suffix;
            $probe = substr($probe, 0, $slash);
        }

        return $path;
    }
}
