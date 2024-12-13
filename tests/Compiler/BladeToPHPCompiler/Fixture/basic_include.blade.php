@include('partials.filename', ['data' => $data])
-----
<?php

/** file: foo.blade.php, line: 1 */
function () use ($data) {
    $data = $data;
};
