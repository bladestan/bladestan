<x-dynamic-component :component="App\MyDynComponent::getComponent()" :option="$option" />
-----
<?php

/** @var Illuminate\View\Factory $__env */
/** @var Illuminate\Support\ViewErrorBag $errors */
/** file: foo.blade.php, line: 1 */
$component = new Illuminate\View\DynamicComponent(component: App\MyDynComponent::getComponent());
