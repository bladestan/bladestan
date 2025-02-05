{{
    /** @var string $foo */
    $foo + 10
}}
-----
<?php

/** file: foo.blade.php, line: 1 */
echo e(
    /** @var string $foo */
    /** file: foo.blade.php, line: 3 */
    $foo + 10
);
