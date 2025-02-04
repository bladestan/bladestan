<x-component :$a :b="$b" c="{{$x}}">{{ $inner }}</x-component>
<x-component :$a :b="$b" c="{{$x}}"/>
-----
<?php

/** @var Illuminate\View\Factory $__env */
/** file: foo.blade.php, line: 1 */
function () use ($a, $b, $x) {
    $a = $a;
    $b = $b;
    $c = '' . e($x) . '';
    function () use ($a, $b, $c) {
        $slot = new \Illuminate\Support\HtmlString();
        $attributes = new \Illuminate\View\ComponentAttributeBag();
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
    function () use ($a, $b, $c) {
        $slot = new \Illuminate\Support\HtmlString();
        $attributes = new \Illuminate\View\ComponentAttributeBag();
        /** file: components/component.blade.php, line: 1 */
        echo e($a . $b);
        /** file: components/component.blade.php, line: 2 */
        echo e($slot);
        /** file: components/component.blade.php, line: 3 */
        echo e($c);
    };
};
