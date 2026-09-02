<?php

declare(strict_types=1);

namespace ViewCallSiteMailableContent;

use App\Models\User;
use Illuminate\Mail\Mailables\Content;

// The view name given positionally and the with: data must both be recognized:
// $title is provided, the template's required $user is not.
function positionalView(): Content
{
    return new Content('signed-template', with: [
        'title' => 'Hello World',
    ]);
}

// A named view: with complete data is clean.
function namedView(User $user): Content
{
    return new Content(view: 'signed-template', with: [
        'title' => 'Hello World',
        'user' => $user,
    ]);
}
