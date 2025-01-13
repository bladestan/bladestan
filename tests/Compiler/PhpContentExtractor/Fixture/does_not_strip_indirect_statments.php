@extends('partial.filename')

{{ $foo }}
-----
<?php
/** file: foo.blade.php, line: 3 */ echo e($foo);
 echo $__env->make('partial.filename', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render();
