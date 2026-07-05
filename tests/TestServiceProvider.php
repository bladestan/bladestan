<?php

namespace Bladestan\Tests;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Compilers\BladeCompiler;

class TestServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        resolve(ViewFactory::class)->getFinder()
            ->addLocation(__DIR__ . '/skeleton/resources/views');
        $this->loadViewsFrom(__DIR__ . '/skeleton/resources/namespace/Test', 'Test');

        // Mirror a namespaced component registration so component templates
        // resolve to their backing class the same way a real app configures it.
        $bladeCompiler = resolve(BladeCompiler::class);
        $bladeCompiler->componentNamespace('App\\View\\Components', 'skeleton');
        $bladeCompiler->anonymousComponentNamespace('components', 'skeleton');
    }
}
