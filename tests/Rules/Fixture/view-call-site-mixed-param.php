<?php

declare(strict_types=1);

namespace ViewCallSiteMixedParam;

use function view;

/**
 * A mixed value (here an explicitly untyped argument) passed for a typed
 * signature variable is accepted below PHPStan's checkExplicitMixed level,
 * exactly as it would be for an ordinary function argument.
 */
function render(mixed $title): void
{
    view('signed-template', [
        'title' => $title,
        'user' => new \App\Models\User(),
    ]);
}
