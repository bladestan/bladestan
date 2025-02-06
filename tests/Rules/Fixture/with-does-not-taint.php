<?php

declare(strict_types=1);

namespace LaravelViewFunction;

use function view;

view('simple_variable')->with('foo', 'bar');

view('foo');
