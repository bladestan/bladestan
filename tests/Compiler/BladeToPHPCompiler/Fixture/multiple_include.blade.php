@include('partials.filename1', ['vehicle' => 'truck'])
@include('partials.filename2', ['animal' => 'frogs'])
-----
<?php

/** @var Illuminate\Support\ViewErrorBag $errors */
/** @var Illuminate\View\Factory $__env */
/** @var Illuminate\Foundation\Application $app */
/** file: foo.blade.php, line: 1 */
function () {
    $vehicle = 'truck';
    $errors = resolve(Illuminate\Support\ViewErrorBag::class);
    $__env = resolve(Illuminate\View\Factory::class);
    $app = resolve(Illuminate\Foundation\Application::class);
};
/** file: foo.blade.php, line: 2 */
function () {
    $animal = 'frogs';
    $errors = resolve(Illuminate\Support\ViewErrorBag::class);
    $__env = resolve(Illuminate\View\Factory::class);
    $app = resolve(Illuminate\Foundation\Application::class);
};
