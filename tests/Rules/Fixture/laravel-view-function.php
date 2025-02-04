<?php

declare(strict_types=1);

namespace LaravelViewFunction;

use function view;

view('foo', [
    'foo' => 'bar',
]);

view('simple_variable')
    ->withFoo('bar')
    ->withBar(10);
view('simple_variable')->with('foo', 'bar');

view('include_with_parameters', [
    'includeData' => [],
]);

$fooBar = [
    'foo' => 'bar',
];

view('foo', $fooBar);
