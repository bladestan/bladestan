<div class="output">
    <h1>{{
        $foo
    }}</h1>
</div>
-----
<?php

/** @var Illuminate\Support\ViewErrorBag $errors */
/** @var Illuminate\View\Factory $__env */
/** @var Illuminate\Foundation\Application $app */
/** file: foo.blade.php, line: 2 */
echo e(
    /** file: foo.blade.php, line: 3 */
    $foo
);
/** file: foo.blade.php, line: 5 */
