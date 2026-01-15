@if (isset($errors))
    @if (count($errors) > 0)
        <div class="alert alert-danger">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif
@endif
-----
<?php

/** @var Illuminate\Support\ViewErrorBag $errors */
/** @var Illuminate\View\Factory $__env */
/** @var Illuminate\Foundation\Application $app */
/** file: foo.blade.php, line: 1 */
if (\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()) {
}
if (isset($errors)) {
    /** file: foo.blade.php, line: 2 */
    if (\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()) {
    }
    if (count($errors) > 0) {
        /** file: foo.blade.php, line: 5 */
        if (\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()) {
            \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop();
        }
        $__currentLoopData = $errors->all();
        $__env->addLoop($__currentLoopData);
        foreach ($__currentLoopData as $error) {
            $__env->incrementLoopIndices();
            $loop = new \Bladestan\ValueObject\Loop();
            if (\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()) {
                \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoop($loop->index);
            }
            /** file: foo.blade.php, line: 6 */
            echo e($error);
            /** file: foo.blade.php, line: 7 */
            if (\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()) {
                \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop();
            }
        }
        $__env->popLoop();
        $loop = null;
        if (\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()) {
            \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop();
        }
        /** file: foo.blade.php, line: 10 */
    }
    if (\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()) {
    }
    /** file: foo.blade.php, line: 11 */
}
if (\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()) {
}
