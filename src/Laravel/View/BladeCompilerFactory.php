<?php

declare(strict_types=1);

namespace Bladestan\Laravel\View;

use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\DynamicComponent;

/**
 * @api factory service in config
 */
final class BladeCompilerFactory
{
    public function create(): BladeCompiler
    {
        $compiler = resolve('blade.compiler');
        $compiler->component('dynamic-component', DynamicComponent::class);
        return $compiler;
    }
}
