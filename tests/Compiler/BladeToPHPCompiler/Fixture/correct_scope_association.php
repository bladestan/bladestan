@if ($a)
@include('partial', ['use' => $a])
@endif
@if ($b)
@include('partial', ['use' => $b])
@endif
-----
<?php

/** @var Illuminate\View\Factory $__env */
/** @var Illuminate\Support\ViewErrorBag $errors */
/** file: foo.blade.php, line: 1 */
if ($a) {
    /** file: foo.blade.php, line: 2 */
    function () use ($__env, $errors, $a) {
        $use = $a;
    };
    /** file: foo.blade.php, line: 3 */
}
/** file: foo.blade.php, line: 4 */
if ($b) {
    /** file: foo.blade.php, line: 5 */
    function () use ($__env, $errors, $b) {
        $use = $b;
    };
    /** file: foo.blade.php, line: 6 */
}
