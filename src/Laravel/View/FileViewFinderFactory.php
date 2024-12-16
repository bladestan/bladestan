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
        private readonly DirectoryHelper $directoryHelper,
    ) {
    }

    public function create(): FileViewFinder
    {
        // @note is the absolute path needed?
        $absoluteTemplatePaths = $this->directoryHelper->absolutizePaths($this->configuration->getTemplatePaths());

        $finder = new FileViewFinder(
            $this->filesystem,
            $absoluteTemplatePaths,
            // @note why SVG?
            ['blade.php', 'svg']
        );
        foreach ($this->configuration->getNamespacedPaths() as $namespace => $path) {
            $finder->addNamespace($namespace, $path);
        }
        return $finder;
    }
}
