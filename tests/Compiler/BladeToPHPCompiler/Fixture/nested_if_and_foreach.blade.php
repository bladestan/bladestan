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

/** @var Illuminate\View\Factory $__env */
/** file: foo.blade.php, line: 1 */
if (isset($errors)) {
    /** file: foo.blade.php, line: 2 */
    if (count($errors) > 0) {
        /** file: foo.blade.php, line: 5 */
        $__currentLoopData = $errors->all();
        foreach ($__currentLoopData as $error) {
            $loop = new \Bladestan\ValueObject\Loop();
            /** file: foo.blade.php, line: 6 */
            echo e($error);
            unset($loop);
        }
        /** file: foo.blade.php, line: 10 */
    }
    /** file: foo.blade.php, line: 11 */
}
