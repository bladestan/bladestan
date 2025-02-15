<x-dynamic-component :component="App\MyDynComponent::getComponent()" :option="$option" />
-----
<?php

/** @var Illuminate\Support\ViewErrorBag $errors */
/** @var Illuminate\View\Factory $__env */
/** @var Illuminate\Foundation\Application $app */
/** file: foo.blade.php, line: 1 */
$component = new Illuminate\View\DynamicComponent(component: App\MyDynComponent::getComponent());
