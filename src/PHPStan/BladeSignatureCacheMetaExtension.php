<?php

declare(strict_types=1);

namespace Bladestan\PHPStan;

use PHPStan\Analyser\ResultCache\ResultCacheMetaExtension;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RecursiveRegexIterator;
use RegexIterator;
use SplFileInfo;
use UnexpectedValueException;

final class BladeSignatureCacheMetaExtension implements ResultCacheMetaExtension
{
    /**
     * @param list<string> $templatePaths
     */
    public function __construct(
        private readonly array $templatePaths
    ) {
    }

    public function getKey(): string
    {
        return 'bladestan-signatures';
    }

    public function getHash(): string
    {
        try {
            $files = $this->discoverBladeFiles($this->templatePaths);
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
     * Recursively finds all *.blade.php files in the given directories.
     *
     * @param list<string> $paths
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
