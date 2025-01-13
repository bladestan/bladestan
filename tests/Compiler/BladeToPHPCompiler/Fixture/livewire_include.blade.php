<livewire:wired-component :b="$b" c="{{$c}}"/>
-----
<?php

/** file: foo.blade.php, line: 1 */
$component = new App\View\Components\WiredComponent();
$component->mount(b: $b);
$component->c = '' . e($c) . '';
