<?php

declare(strict_types=1);

namespace ViewCallSiteCorrect;

use App\Models\User;

use function view;

view('signed-template', [
    'title' => 'Hello World',
    'user' => new User(),
]);