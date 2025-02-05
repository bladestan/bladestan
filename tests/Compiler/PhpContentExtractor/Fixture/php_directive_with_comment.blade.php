@php
    /** @var string $foo */
    $foo = config('foo.bar');
@endphp

{{ $foo }}
-----
<?php

/** file: foo.blade.php, line: 1 */
/** @var string $foo */
/** file: foo.blade.php, line: 3 */
$foo = config('foo.bar');
/** file: foo.blade.php, line: 4 */
/** file: foo.blade.php, line: 6 */
echo e($foo);
