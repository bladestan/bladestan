<?php

declare(strict_types=1);

namespace LaravelViewFunction;

use function view;

view('Test::namespacedView', [
    'variable' => 'foobar',
]);
