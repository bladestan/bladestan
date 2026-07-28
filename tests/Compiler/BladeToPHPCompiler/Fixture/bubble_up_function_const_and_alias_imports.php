@php
use const PHP_EOL;
use function strlen as stringLength;
use My\Name\Space2 as Space2Alias;
@endphp

{{ @foo }}
-----
<?php

/** @var Illuminate\Support\ViewErrorBag $errors */
/** @var Illuminate\View\Factory $__env */
/** @var Illuminate\Foundation\Application $app */
use const PHP_EOL;
use function strlen as stringLength;
use My\Name\Space2 as Space2Alias;
/** file: foo.blade.php, line: 1 */
/** file: foo.blade.php, line: 2 */
/** file: foo.blade.php, line: 3 */
/** file: foo.blade.php, line: 4 */
/** file: foo.blade.php, line: 5 */
/** file: foo.blade.php, line: 7 */
echo e(@foo);
