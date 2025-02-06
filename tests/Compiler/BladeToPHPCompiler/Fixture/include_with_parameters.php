@include('bar', $includeData)
@if(true)
	@include('bar')
@endif
-----
<?php

/** @var Illuminate\View\Factory $__env */
/** @var Illuminate\Support\ViewErrorBag $errors */
/** file: foo.blade.php, line: 1 */
function () use ($__env, $errors, $includeData) {
    extract($includeData);
};
/** file: foo.blade.php, line: 2 */
if (true) {
    /** file: foo.blade.php, line: 3 */
    function () use ($__env, $errors) {
    };
    /** file: foo.blade.php, line: 4 */
}
