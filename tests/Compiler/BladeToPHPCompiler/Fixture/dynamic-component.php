<x-dynamic-component component="{{ App\MyDynComponent::getComponent() }}" :option="$option" />
-----
<?php

/** file: foo.blade.php, line: 1 */
$component = new Illuminate\View\DynamicComponent(component: '' . e(App\MyDynComponent::getComponent()) . '');
