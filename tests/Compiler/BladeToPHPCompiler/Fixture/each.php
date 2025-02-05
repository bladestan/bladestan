@each('view.name', $jobs, 'job')
@each('view.name', $jobs, 'job', 'view.empty')
@each('view.name', $jobs, 'job', 'raw|No jobs')
-----
<?php

/** @var Illuminate\View\Factory $__env */
/** @var Illuminate\Support\ViewErrorBag $errors */
/** file: foo.blade.php, line: 1 */
foreach ($jobs as $key => $job) {
    function () use ($__env, $errors, $key, $job) {
        $key = $key;
        $job = $job;
    };
}
/** file: foo.blade.php, line: 2 */
if (count($jobs)) {
    foreach ($jobs as $key => $job) {
        function () use ($__env, $errors, $key, $job) {
            $key = $key;
            $job = $job;
        };
    }
} else {
    function () use ($__env, $errors) {
    };
}
/** file: foo.blade.php, line: 3 */
if (count($jobs)) {
    foreach ($jobs as $key => $job) {
        function () use ($__env, $errors, $key, $job) {
            $key = $key;
            $job = $job;
        };
    }
} else {
    echo 'No jobs';
}
