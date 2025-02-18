@foreach($foos as $value)
	@include('partial', ['foo' => $value])
@endforeach
-----
<?php

/** @var Illuminate\Support\ViewErrorBag $errors */
/** @var Illuminate\View\Factory $__env */
/** @var Illuminate\Foundation\Application $app */
/** file: foo.blade.php, line: 1 */
$__currentLoopData = $foos;
$__env->addLoop($__currentLoopData);
foreach ($__currentLoopData as $value) {
    $__env->incrementLoopIndices();
    $loop = new \Bladestan\ValueObject\Loop();
    /** file: foo.blade.php, line: 2 */
    function () use ($value) {
        $foo = $value;
        $errors = resolve(Illuminate\Support\ViewErrorBag::class);
        $__env = resolve(Illuminate\View\Factory::class);
        $app = resolve(Illuminate\Foundation\Application::class);
    };
    /** file: foo.blade.php, line: 3 */
}
$__env->popLoop();
$loop = null;
