<livewire:wired-component :b="$b" c="{{$c}}"/>
-----
<?php

/** @var Illuminate\View\Factory $__env */
/** @var Illuminate\Support\ViewErrorBag $errors */
/** file: foo.blade.php, line: 1 */
$component = new App\View\Components\WiredComponent();
$component->mount(b: $b);
$component->c = '' . e($c) . '';
