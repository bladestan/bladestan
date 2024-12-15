<?php

declare(strict_types=1);

namespace Bladestan\Laravel\View;

use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;

/**
 * @api factory service in config
 */
final class BladeCompilerFactory
{
    public function create(): BladeCompiler
    {
        $filesystem = new Filesystem();

        return new BladeCompiler($filesystem, sys_get_temp_dir());
    }
}
