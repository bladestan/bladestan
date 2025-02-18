<?php

namespace Bladestan\Tests;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Support\ServiceProvider;

class TestServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        resolve(ViewFactory::class)->getFinder()
            ->addLocation(__DIR__ . '/skeleton/resources/views');
        $this->loadViewsFrom(__DIR__ . '/skeleton/resources/namespace/Test', 'Test');
    }
}
