<?php

declare(strict_types=1);

namespace Bladestan\Support;

use PHPStan\File\FileHelper;

final class DirectoryHelper
{
    public function __construct(
        private readonly FileHelper $fileHelper
    ) {
    }

    /**
     * @param string[] $paths
     *
     * @return string[]
     */
    public function absolutizePaths(array $paths): array
    {
        return array_map(function (string $path): string {
            $path = $this->fileHelper->absolutizePath($path);

            return str_replace(['phar://', 'vendor/phpstan/phpstan/phpstan.phar/'], ['', ''], $path);
        }, $paths);
    }
}
