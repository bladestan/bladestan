<?php

declare(strict_types=1);

namespace ViewCallSiteExtendsMissingParentParam;

use App\Models\User;

use function view;

view('extends-template', [
    'title' => 'Hello World',
    'user' => new User(),
]);
