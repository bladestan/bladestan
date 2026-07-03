<?php

declare(strict_types=1);

namespace ViewCallSiteMissingParam;

use function view;

view('signed-template', [
    'title' => 'Hello World',
]);