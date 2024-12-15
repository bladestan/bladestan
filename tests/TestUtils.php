<?php

declare(strict_types=1);

namespace Bladestan\Tests;

use Iterator;
use Webmozart\Assert\Assert;

final class TestUtils
{
    /**
     * @return array{0: string, 1: string}
     */
    public static function splitFixture(string $filePath): array
    {
        Assert::fileExists($filePath);

        /** @var string $fileContents */
        $fileContents = file_get_contents($filePath);

        $stringsCollection = str($fileContents)
            ->split("#-----\n#")
            ->values();

        return [$stringsCollection[0], $stringsCollection[1]];
    }

    public static function yieldDirectory(string $directory): Iterator
    {
        /** @var list<string> $filePaths */
        $filePaths = glob($directory . '/*');

        foreach ($filePaths as $filePath) {
            yield [$filePath];
        }
    }
}
