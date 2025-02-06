<?php

declare(strict_types=1);

namespace Bladestan\Laravel\View;

use Bladestan\Configuration\Configuration;
use Bladestan\Support\DirectoryHelper;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\FileViewFinder;

/**
 * @api factory service in config
 */
final class FileViewFinderFactory
{
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly Configuration $configuration,
        // @note is the absolute path needed?
        private readonly DirectoryHelper $directoryHelper,
    ) {
    }

    public function create(): FileViewFinder
    {
        $basePaths = [];
        $namespacedPaths = [];

        $paths = $this->configuration->getTemplatePaths();
        foreach ($paths as $path) {
            if (str_contains($path, ':')) {
                $components = explode(':', $path);
                $namespacedPaths[$components[0]][] = $components[1];
            } else {
                $basePaths[] = $path;
            }
        }

        $fileViewFinder = new FileViewFinder($this->filesystem, $this->directoryHelper->absolutizePaths($basePaths));

        foreach ($namespacedPaths as $namespace => $paths) {
            $fileViewFinder->addNamespace($namespace, $this->directoryHelper->absolutizePaths($paths));
        }

        return $fileViewFinder;
    }
}
