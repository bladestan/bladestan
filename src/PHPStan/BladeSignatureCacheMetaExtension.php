<?php

declare(strict_types=1);

namespace Bladestan\PHPStan;

use Bladestan\Compiler\SignatureExtractor;
use Bladestan\Discovery\BladeFileIterator;
use Bladestan\Laravel\ApplicationBooter;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\View\FileViewFinder;
use PHPStan\Analyser\ResultCache\ResultCacheMetaExtension;
use SplFileInfo;
use Throwable;
use UnexpectedValueException;

/**
 * Invalidates PHPStan's entire result cache when any template's *contract*
 * changes: its signature docblock, @extends chain membership, or @props.
 *
 * Call sites (view() calls in PHP files and @include call sites in compiled
 * templates) are validated against these contracts, but PHPStan's dependency
 * resolver has no way to know a PHP file depends on a blade file — so any
 * contract change must conservatively invalidate everything.
 *
 * Deliberately does NOT hash full template contents: body edits are already
 * tracked precisely through the compiled file's own hash, and hashing whole
 * files here would force a full re-analysis on every template edit.
 */
final class BladeSignatureCacheMetaExtension implements ResultCacheMetaExtension
{
    public function __construct(
        private readonly SignatureExtractor $signatureExtractor,
    ) {
    }

    public function getKey(): string
    {
        return 'bladestan-signatures';
    }

    public function getHash(): string
    {
        try {
            $paths = $this->getViewPaths();
        } catch (Throwable) {
            // No application to ask, so there are no view paths and nothing to
            // hash. This is the normal state of a project without a bootable
            // Laravel application, and hashing it as "no templates" keeps such
            // a project from re-analysing everything on every run. When the
            // application exists but is broken, PHPStan's own run fails on the
            // same boot once the bootstrap file executes, and this hash never
            // gets to matter.
            $paths = [];
        }

        try {
            $files = $this->discoverBladeFiles($paths);
        } catch (UnexpectedValueException) {
            // return a unique hash so the cache is conservatively invalidated.
            return hash('xxh128', microtime());
        }

        $hashContext = hash_init('xxh128');

        foreach ($files as $file) {
            $contents = @file_get_contents($file);
            if ($contents === false) {
                continue;
            }

            hash_update($hashContext, $file);
            hash_update($hashContext, $this->signatureExtractor->extractSignatureRelevantContent($contents));
        }

        return hash_final($hashContext);
    }

    /**
     * The directories Laravel resolves views from.
     *
     * This runs while PHPStan restores its result cache, which the analyse
     * command does before it executes any bootstrap file — so unlike every
     * other place Bladestan asks Laravel a question, there is no application
     * running yet and one has to be booted here.
     *
     * @return array<string>
     * @throws Throwable when no application can be booted
     */
    private function getViewPaths(): array
    {
        $application = ApplicationBooter::boot();
        if (! $application instanceof Container) {
            return [];
        }

        $finder = $application->make(ViewFactory::class)
            ->getFinder();
        assert($finder instanceof FileViewFinder);

        /** @var array<array<string>> $hints */
        $hints = $finder->getHints();

        return array_merge($finder->getPaths(), ...array_values($hints));
    }

    /**
     * Recursively finds all *.blade.php files in the given directories.
     *
     * @param array<string> $paths
     * @return list<string>
     * @throws UnexpectedValueException
     */
    private function discoverBladeFiles(array $paths): array
    {
        $files = [];

        foreach ($paths as $path) {
            if (! is_dir($path)) {
                continue;
            }

            /** @var SplFileInfo $fileInfo */
            foreach (BladeFileIterator::over($path) as $fileInfo) {
                $files[] = $fileInfo->getPathname();
            }
        }

        // Deterministic order so the hash is stable across runs
        sort($files);

        return $files;
    }
}
