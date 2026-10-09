<?php

declare(strict_types=1);

namespace ViewCallSiteViewMethods;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\View\Factory;

// View::make() form: $user is missing.
function make(Factory $factory): void
{
    $factory->make('signed-template', ['title' => 'Hello']);
}

// first() validates against the last (fallback) candidate; $user is missing.
function first(Factory $factory): void
{
    $factory->first(['optional-override', 'signed-template'], ['title' => 'Hello']);
}

// renderWhen()'s view is the 2nd arg, data the 3rd; $user is missing.
function renderWhen(Factory $factory): void
{
    $factory->renderWhen(true, 'signed-template', ['title' => 'Hello']);
}

// renderUnless() has the same shape as renderWhen().
function renderUnless(Factory $factory): void
{
    $factory->renderUnless(false, 'signed-template', ['title' => 'Hello']);
}

// renderEach() forwards $key and the named iterator var to the partial; a
// complete array satisfies render-each-item's signature.
function renderEachClean(Factory $factory): void
{
    $factory->renderEach('render-each-item', ['a', 'b'], 'item');
}

// A Mailable's markdown() view is validated too; $user is missing.
function mailableMarkdown(Mailable $mailable): void
{
    $mailable->markdown('signed-template', ['title' => 'Hello']);
}
