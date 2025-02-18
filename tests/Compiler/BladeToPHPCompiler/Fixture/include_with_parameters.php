@include('partial', $includeData)
@if(true)
	@include('partial')
@endif
-----
<?php

/** @var Illuminate\Support\ViewErrorBag $errors */
/** @var Illuminate\View\Factory $__env */
/** @var Illuminate\Foundation\Application $app */
/** file: foo.blade.php, line: 1 */
function () use ($includeData) {
    $errors = resolve(Illuminate\Support\ViewErrorBag::class);
    $__env = resolve(Illuminate\View\Factory::class);
    $app = resolve(Illuminate\Foundation\Application::class);
    extract($includeData);
};
/** file: foo.blade.php, line: 2 */
if (true) {
    /** file: foo.blade.php, line: 3 */
    function () {
        $errors = resolve(Illuminate\Support\ViewErrorBag::class);
        $__env = resolve(Illuminate\View\Factory::class);
        $app = resolve(Illuminate\Foundation\Application::class);
    };
    /** file: foo.blade.php, line: 4 */
}
