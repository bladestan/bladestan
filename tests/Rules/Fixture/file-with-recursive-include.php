<?php

declare(strict_types=1);

namespace LaravelViewFunction;

use function view;

view('file_with_recursive_include', [
    'foo' => 'foo',
]);
