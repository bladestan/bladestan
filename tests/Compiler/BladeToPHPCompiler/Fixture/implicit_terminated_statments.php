<?php echo '' ?>
{{ $foo }}
@php
    echo ''
@endphp
@if ($foo)
    {{ $foo }}
@endif
-----
<?php

/** @var Illuminate\View\Factory $__env */
/** file: foo.blade.php, line: 1 */
echo '';
/** file: foo.blade.php, line: 2 */
echo e($foo);
/** file: foo.blade.php, line: 3 */
/** file: foo.blade.php, line: 4 */
echo '';
/** file: foo.blade.php, line: 5 */
/** file: foo.blade.php, line: 6 */
if ($foo) {
    /** file: foo.blade.php, line: 7 */
    echo e($foo);
    /** file: foo.blade.php, line: 8 */
}
