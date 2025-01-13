<div class="output">
    <h1>{{ $foo }} {{ $bar }}</h1>
</div>
-----
<?php
/** file: foo.blade.php, line: 2 */ echo e($foo); echo e($bar);
