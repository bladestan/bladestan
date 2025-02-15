@include('partials.filename', ['data' => $data])
-----
<?php

/** @var Illuminate\Support\ViewErrorBag $errors */
/** @var Illuminate\View\Factory $__env */
/** @var Illuminate\Foundation\Application $app */
/** file: foo.blade.php, line: 1 */
function () use ($data) {
    $data = $data;
    $errors = resolve(Illuminate\Support\ViewErrorBag::class);
    $__env = resolve(Illuminate\View\Factory::class);
    $app = resolve(Illuminate\Foundation\Application::class);
};
