@php
    $name = 'John Doe';
    $age = 25;
@endphp
-----
<?php

/** file: foo.blade.php, line: 1 */
/** file: foo.blade.php, line: 2 */
$name = 'John Doe';
/** file: foo.blade.php, line: 3 */
$age = 25;
/** file: foo.blade.php, line: 4 */
