<livewire:wired-component :b="$b" c="{{$c}}" :account-id="$b" scope-type="account"/>
-----
<?php

/** @var Illuminate\Support\ViewErrorBag $errors */
/** @var Illuminate\View\Factory $__env */
/** @var Illuminate\Foundation\Application $app */
/** file: foo.blade.php, line: 1 */
$component = new App\Livewire\WiredComponent();
$component->mount(b: $b);
$component->c = '' . e($c) . '';
$component->accountId = $b;
$component->scopeType = 'account';
