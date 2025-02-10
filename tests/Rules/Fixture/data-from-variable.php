<?php

declare(strict_types=1);

namespace LaravelViewFunction;

use function view;

$fooBar = [
    'foo' => 'bar',
];

view('foo', $fooBar);
