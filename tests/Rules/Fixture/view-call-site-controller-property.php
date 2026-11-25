<?php

declare(strict_types=1);

namespace ViewCallSiteControllerProperty;

use App\Models\User;

use function view;

// A plain controller's public properties are never passed to the view, so the
// $user property must not satisfy the template's required $user parameter.
final class ProfileController
{
    public User $user;

    public function show(): mixed
    {
        return view('signed-template', [
            'title' => 'Hello World',
        ]);
    }
}
