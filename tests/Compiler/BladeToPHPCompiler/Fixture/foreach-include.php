@foreach($foos as $value)
	@include('bar', ['foo' => $value])
@endforeach
-----
<?php

/** @var Illuminate\View\Factory $__env */
/** @var Illuminate\Support\ViewErrorBag $errors */
/** file: foo.blade.php, line: 1 */
$__currentLoopData = $foos;
$__env->addLoop($__currentLoopData);
foreach ($__currentLoopData as $value) {
    $__env->incrementLoopIndices();
    $loop = new \Bladestan\ValueObject\Loop();
    /** file: foo.blade.php, line: 2 */
    function () use ($__env, $errors, $value) {
        $foo = $value;
    };
    /** file: foo.blade.php, line: 3 */
}
$__env->popLoop();
$loop = null;
