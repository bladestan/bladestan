<livewire:component :b="$b" c="{{$c}}"/>
-----
<?php

/** file: foo.blade.php, line: 1 */
echo Livewire\Component::resolve(['view' => 'component', 'data' => ['b' => $b, 'c' => '' . e($c) . '']])->render();
