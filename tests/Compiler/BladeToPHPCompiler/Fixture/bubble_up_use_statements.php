@php
use My\Name\Space2;
@endphp

{{ @foo }}

@include('partials.has_use')
@include('partials.has_use')
-----
<?php

/** @var Illuminate\Support\ViewErrorBag $errors */
/** @var Illuminate\View\Factory $__env */
/** @var Illuminate\Foundation\Application $app */
use My\Name\Space2;
use My\Name\Space;
/** file: foo.blade.php, line: 1 */
/** file: foo.blade.php, line: 2 */
/** file: foo.blade.php, line: 3 */
/** file: foo.blade.php, line: 5 */
echo e(@foo);
/** file: foo.blade.php, line: 7 */
function () {
    $errors = resolve(Illuminate\Support\ViewErrorBag::class);
    $__env = resolve(Illuminate\View\Factory::class);
    $app = resolve(Illuminate\Foundation\Application::class);
    /** file: partials/has_use.blade.php, line: 1 */
    /** file: partials/has_use.blade.php, line: 2 */
    /** file: partials/has_use.blade.php, line: 3 */
};
/** file: foo.blade.php, line: 8 */
function () {
    $errors = resolve(Illuminate\Support\ViewErrorBag::class);
    $__env = resolve(Illuminate\View\Factory::class);
    $app = resolve(Illuminate\Foundation\Application::class);
    /** file: partials/has_use.blade.php, line: 1 */
    /** file: partials/has_use.blade.php, line: 2 */
    /** file: partials/has_use.blade.php, line: 3 */
};
