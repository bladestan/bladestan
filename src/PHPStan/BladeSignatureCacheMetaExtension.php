<?php

declare(strict_types=1);

namespace Bladestan\PHPStan;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\View\FileViewFinder;
use PHPStan\Analyser\ResultCache\ResultCacheMetaExtension;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RecursiveRegexIterator;
use RegexIterator;
use SplFileInfo;
use UnexpectedValueException;

final class BladeSignatureCacheMetaExtension implements ResultCacheMetaExtension
{
    public function getKey(): string
    {
        return 'bladestan-signatures';
    }

    public function getHash(): string
    {
        $paths = $this->getViewPaths();

        try {
            $files = $this->discoverBladeFiles($paths);
        } catch (UnexpectedValueException) {
            // return a unique hash so the cache is conservatively invalidated.
            return hash('xxh128', microtime());
        }

        $hashContext = hash_init('xxh128');

        foreach ($files as $file) {
            hash_update($hashContext, $file);
            hash_update_file($hashContext, $file);
        }

        return hash_final($hashContext);
    }

    /**
     * @return array<string>
     */
    private function getViewPaths(): array
    {
        $finder = resolve(ViewFactory::class)->getFinder();
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

            $directory = new RecursiveDirectoryIterator($path);
            $iterator = new RecursiveIteratorIterator($directory);
            $regex = new RegexIterator($iterator, '/\.blade\.php$/', RecursiveRegexIterator::MATCH);

            /** @var SplFileInfo $fileInfo */
            foreach ($regex as $fileInfo) {
                $files[] = $fileInfo->getPathname();
            }
        }

        // Deterministic order so the hash is stable across runs
        sort($files);

        return $files;
    }
}
