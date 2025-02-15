@each('view.name', $jobs, 'job')
@each('view.name', $jobs, 'job', 'view.empty')
@each('view.name', $jobs, 'job', 'raw|No jobs')
-----
<?php

/** @var Illuminate\Support\ViewErrorBag $errors */
/** @var Illuminate\View\Factory $__env */
/** @var Illuminate\Foundation\Application $app */
/** file: foo.blade.php, line: 1 */
foreach ($jobs as $key => $job) {
    function () use ($key, $job) {
        $key = $key;
        $job = $job;
        $errors = resolve(Illuminate\Support\ViewErrorBag::class);
        $__env = resolve(Illuminate\View\Factory::class);
        $app = resolve(Illuminate\Foundation\Application::class);
    };
}
/** file: foo.blade.php, line: 2 */
if (count($jobs)) {
    foreach ($jobs as $key => $job) {
        function () use ($key, $job) {
            $key = $key;
            $job = $job;
            $errors = resolve(Illuminate\Support\ViewErrorBag::class);
            $__env = resolve(Illuminate\View\Factory::class);
            $app = resolve(Illuminate\Foundation\Application::class);
        };
    }
} else {
    function () {
        $errors = resolve(Illuminate\Support\ViewErrorBag::class);
        $__env = resolve(Illuminate\View\Factory::class);
        $app = resolve(Illuminate\Foundation\Application::class);
    };
}
/** file: foo.blade.php, line: 3 */
if (count($jobs)) {
    foreach ($jobs as $key => $job) {
        function () use ($key, $job) {
            $key = $key;
            $job = $job;
            $errors = resolve(Illuminate\Support\ViewErrorBag::class);
            $__env = resolve(Illuminate\View\Factory::class);
            $app = resolve(Illuminate\Foundation\Application::class);
        };
    }
} else {
    echo 'No jobs';
}
