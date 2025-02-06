@includeWhen($condition, 'view.name', ['foo' => 'bar'])
@includeUnless(!$condition, 'view.name', ['foo' => 'bar'])
-----
<?php

/** @var Illuminate\View\Factory $__env */
/** @var Illuminate\Support\ViewErrorBag $errors */
/** file: foo.blade.php, line: 1 */
if ($condition) {
    function () use ($__env, $errors) {
        $foo = 'bar';
    };
}
/** file: foo.blade.php, line: 2 */
if (!!$condition) {
    function () use ($__env, $errors) {
        $foo = 'bar';
    };
}
