<x-component :$a :b="$b" c="{{$x}}">{{ $inner }}</x-component>
<x-component :$a :b="$b" c="{{$x}}"/>
-----
<?php

/** @var Illuminate\Support\ViewErrorBag $errors */
/** @var Illuminate\View\Factory $__env */
/** @var Illuminate\Foundation\Application $app */
/** file: foo.blade.php, line: 1 */
function () use ($a, $b, $x) {
    $a = $a;
    $b = $b;
    $c = '' . e($x) . '';
    $errors = resolve(Illuminate\Support\ViewErrorBag::class);
    $__env = resolve(Illuminate\View\Factory::class);
    $app = resolve(Illuminate\Foundation\Application::class);
    $slot = new \Illuminate\View\ComponentSlot();
    $attributes = new \Illuminate\View\ComponentAttributeBag();
    $componentName = Bladestan\ValueObject\Types::getString();
    function () use ($a, $b, $c, $errors, $__env, $app, $slot, $attributes, $componentName) {
        /** file: components/component.blade.php, line: 1 */
        echo e($a . $b);
        /** file: components/component.blade.php, line: 2 */
        echo e($slot);
        /** file: components/component.blade.php, line: 3 */
        echo e($c);
    };
};
echo e($inner);
/** file: foo.blade.php, line: 2 */
function () use ($a, $b, $x) {
    $a = $a;
    $b = $b;
    $c = '' . e($x) . '';
    $errors = resolve(Illuminate\Support\ViewErrorBag::class);
    $__env = resolve(Illuminate\View\Factory::class);
    $app = resolve(Illuminate\Foundation\Application::class);
    $slot = new \Illuminate\View\ComponentSlot();
    $attributes = new \Illuminate\View\ComponentAttributeBag();
    $componentName = Bladestan\ValueObject\Types::getString();
    function () use ($a, $b, $c, $errors, $__env, $app, $slot, $attributes, $componentName) {
        /** file: components/component.blade.php, line: 1 */
        echo e($a . $b);
        /** file: components/component.blade.php, line: 2 */
        echo e($slot);
        /** file: components/component.blade.php, line: 3 */
        echo e($c);
    };
};
