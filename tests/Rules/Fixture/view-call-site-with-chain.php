<?php

declare(strict_types=1);

namespace ViewCallSiteWithChain;

use App\Models\User;

use function view;

// A fluent ->with() chain provides every required variable with correct types.
view('signed-template')
    ->with('title', 'Hello World')
    ->with('user', new User());

// The array form of ->with() resolves the same way.
view('signed-template')
    ->with([
        'title' => 'Hello World',
        'user' => new User(),
    ]);

// A wrong type passed through ->with() is still reported.
view('signed-template')
    ->with('title', 5)
    ->with('user', new User());
