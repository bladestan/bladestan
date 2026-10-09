<?php

declare(strict_types=1);

namespace ViewCallSiteWithVariableChain;

use App\Models\User;

use function view;

// The view is captured in a variable, then ->with() is called on the variable
// in a later statement. The data still has to satisfy the signature.
$view = view('signed-template');
$view->with('title', 'Hello World')
    ->with('user', new User());
