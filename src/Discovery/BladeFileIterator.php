<?php

declare(strict_types=1);

namespace Bladestan\Discovery;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RecursiveRegexIterator;
use RegexIterator;
use SplFileInfo;
use UnexpectedValueException;

/**
 * The one recursive scan for `*.blade.php` files under a directory.
 *
 * Template compilation and the result-cache meta hash must see the exact same
 * set of templates: if one scan finds a template the other misses, editing that
 * template's signature would recompile it without invalidating the cached
 * call-site results that depend on it (or vice versa). Sharing this iterator
 * keeps the two from drifting.
 *
 * The scan follows symlinked directories to match Laravel's view finder and
 * PHPStan's own FileFinder (which uses followLinks()); a bare
 * RecursiveDirectoryIterator does not descend into them, so templates behind a
 * symlinked view subdirectory would otherwise be invisible to both.
 */
final class BladeFileIterator
{
    /**
     * @return iterable<SplFileInfo>
     * @throws UnexpectedValueException
     */
    public static function over(string $directory): iterable
    {
        $recursiveDirectoryIterator = new RecursiveDirectoryIterator(
            $directory,
            FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS,
        );
        $recursiveIteratorIterator = new RecursiveIteratorIterator($recursiveDirectoryIterator);
        $regexIterator = new RegexIterator($recursiveIteratorIterator, '/\.blade\.php$/', RecursiveRegexIterator::MATCH);

        /** @var SplFileInfo $fileInfo */
        foreach ($regexIterator as $fileInfo) {
            yield $fileInfo;
        }
    }
}
