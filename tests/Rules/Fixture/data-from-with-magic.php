<?php

declare(strict_types=1);

namespace LaravelViewFunction;

use function view;

view('simple_variable')
    ->withFoo('bar')
    ->withBar(10);
