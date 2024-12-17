<html lang="en">
    <body>
        <div class="container">
            <span>$foo is string. So it should error: </span> <pre>{{ $foo + 10 }}</pre>
        </div>

        <div class="included">
            {{-- We are overrding the $foo from before. Now it'll be integer only inside the included view. --}}
            @include('included_view', ['foo' => 10, 'bar' => $foo . 'bar'])
        </div>

        <div class="container">
            <span>$foo is string again here. So it should error: </span> <pre>{{ $foo + 20 }}</pre>
            <span>$bar was defined inside the include view. So it should error here: </span> <pre>{{ $bar }}</pre>
        </div>
    </body>
</html>
-----
<?php
/** file: foo.blade.php, line: 4 */ echo e($foo + 10);
/** file: foo.blade.php, line: 9 */ echo $__env->make('included_view', ['foo' => 10, 'bar' => $foo . 'bar'], \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render();
/** file: foo.blade.php, line: 13 */ echo e($foo + 20);
/** file: foo.blade.php, line: 14 */ echo e($bar);
