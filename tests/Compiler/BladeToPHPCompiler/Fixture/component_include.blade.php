<x-component :$a :b="$b" c="{{$c}}">{{ $inner }}</x-component>
<x-component :$a :b="$b" c="{{$c}}"/>
-----
<?php

/** file: foo.blade.php, line: 1 */
echo Illuminate\View\AnonymousComponent::resolve(['view' => 'component', 'data' => ['a' => $a, 'b' => $b, 'c' => '' . e($c) . '']])->render();
echo e($inner);
/** file: foo.blade.php, line: 2 */
echo Illuminate\View\AnonymousComponent::resolve(['view' => 'component', 'data' => ['a' => $a, 'b' => $b, 'c' => '' . e($c) . '']])->render();
