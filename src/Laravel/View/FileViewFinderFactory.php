<?php

declare(strict_types=1);

namespace Bladestan\Laravel\View;

use Bladestan\Configuration\Configuration;
use Bladestan\Support\DirectoryHelper;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\View\Factory;

/**
 * @api factory service in config
 */
final class FileViewFinderFactory
{
    public function __construct(
        private readonly Configuration $configuration,
        // @note is the absolute path needed?
        private readonly DirectoryHelper $directoryHelper,
    ) {
    }

    /**
     * @throws BindingResolutionException
     */
    public function create(): Factory
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

        $fileViewFinder = app()
            ->make(Factory::class);

        foreach ($this->directoryHelper->absolutizePaths($basePaths) as $path) {
            $fileViewFinder->addLocation($path);
        }

        foreach ($namespacedPaths as $namespace => $paths) {
            $fileViewFinder->addNamespace($namespace, $this->directoryHelper->absolutizePaths($paths));
        }

        return $fileViewFinder;
    }
}
