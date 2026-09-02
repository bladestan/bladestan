<?php

namespace Bladestan\Tests;

use App\Contexts\Widgets\AliasedWidget;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Compilers\BladeCompiler;
use Livewire\Livewire;

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

        // The same pair with the anonymous directory rooted at the view
        // namespace, the form that keeps two view trees unambiguous when both
        // are on the view path list.
        $bladeCompiler->componentNamespace('App\\View\\Components', 'rooted');
        $bladeCompiler->anonymousComponentNamespace('rooted::components', 'rooted');

        // A Livewire component registered under an explicit alias, outside
        // livewire.class_namespace, so only Livewire's own registry can name it.
        Livewire::component('cart.preview', AliasedWidget::class);
    }
}
