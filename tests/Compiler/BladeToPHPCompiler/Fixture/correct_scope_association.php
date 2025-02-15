@if ($a)
@include('partial', ['use' => $a])
@endif
@if ($b)
@include('partial', ['use' => $b])
@endif
-----
<?php

/** @var Illuminate\Support\ViewErrorBag $errors */
/** @var Illuminate\View\Factory $__env */
/** @var Illuminate\Foundation\Application $app */
/** file: foo.blade.php, line: 1 */
if ($a) {
    /** file: foo.blade.php, line: 2 */
    function () use ($a) {
        $use = $a;
        $errors = resolve(Illuminate\Support\ViewErrorBag::class);
        $__env = resolve(Illuminate\View\Factory::class);
        $app = resolve(Illuminate\Foundation\Application::class);
    };
    /** file: foo.blade.php, line: 3 */
}
/** file: foo.blade.php, line: 4 */
if ($b) {
    /** file: foo.blade.php, line: 5 */
    function () use ($b) {
        $use = $b;
        $errors = resolve(Illuminate\Support\ViewErrorBag::class);
        $__env = resolve(Illuminate\View\Factory::class);
        $app = resolve(Illuminate\Foundation\Application::class);
    };
    /** file: foo.blade.php, line: 6 */
}
