@includeWhen($condition, 'view.name', ['foo' => 'bar'])
@includeUnless(!$condition, 'view.name', ['foo' => 'bar'])
-----
<?php

/** @var Illuminate\Support\ViewErrorBag $errors */
/** @var Illuminate\View\Factory $__env */
/** @var Illuminate\Foundation\Application $app */
/** file: foo.blade.php, line: 1 */
if ($condition) {
    function () {
        $foo = 'bar';
        $errors = resolve(Illuminate\Support\ViewErrorBag::class);
        $__env = resolve(Illuminate\View\Factory::class);
        $app = resolve(Illuminate\Foundation\Application::class);
    };
}
/** file: foo.blade.php, line: 2 */
if (!!$condition) {
    function () {
        $foo = 'bar';
        $errors = resolve(Illuminate\Support\ViewErrorBag::class);
        $__env = resolve(Illuminate\View\Factory::class);
        $app = resolve(Illuminate\Foundation\Application::class);
    };
}
