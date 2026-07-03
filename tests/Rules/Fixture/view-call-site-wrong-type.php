<?php

declare(strict_types=1);

namespace ViewCallSiteWrongType;

use function view;

view('signed-template', [
    'title' => 123,
    'user' => new \App\Models\User(),
]);