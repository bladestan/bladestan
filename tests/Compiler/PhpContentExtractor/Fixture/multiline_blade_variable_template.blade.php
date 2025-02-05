{{
    $foo
}}
-----
<?php

/** file: foo.blade.php, line: 1 */
echo e(
    /** file: foo.blade.php, line: 2 */
    $foo
);
