@include('partials.filename1', ['vehicle' => 'truck'])
@include('partials.filename2', ['animal' => 'frogs'])
-----
<?php

/** @var Illuminate\View\Factory $__env */
/** @var Illuminate\Support\ViewErrorBag $errors */
/** file: foo.blade.php, line: 1 */
function () use ($__env, $errors) {
    $vehicle = 'truck';
};
/** file: foo.blade.php, line: 2 */
function () use ($__env, $errors) {
    $animal = 'frogs';
};
