<?php

declare(strict_types=1);

namespace LaravelViewFunction;

use function view;

$foo = 'foo';
view('simple_variable', compact('foo'));
