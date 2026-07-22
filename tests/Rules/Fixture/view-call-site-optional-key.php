<?php

declare(strict_types=1);

namespace ViewCallSiteOptionalKey;

use App\Models\User;

use function view;

/**
 * An optional array-shape key may be absent at runtime, so it does not satisfy
 * the template's required $user; the guaranteed $title is accepted.
 *
 * @param array{title: string, user?: User} $data
 */
function withOptionalUser(array $data): void
{
    view('signed-template', $data);
}
