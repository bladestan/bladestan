<?php

declare(strict_types=1);

namespace ForbiddenFunctions;

use function view;

view('forbidden_functions_no_echo', [
    'foo' => 'bar',
]);
