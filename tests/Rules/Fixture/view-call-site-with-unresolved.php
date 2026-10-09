<?php

declare(strict_types=1);

namespace ViewCallSiteWithUnresolved;

use function view;

// compact() inside ->with() hides which variables are passed. The site is
// unresolved, not "nothing passed", so no signature variable is reported missing.
view('signed-template')->with(compact('title', 'user'));

/**
 * @param array<string, mixed> $data
 */
function withArrayVariable(array $data): void
{
    // ->with($array) with an opaque array is likewise unresolved.
    view('signed-template')->with($data);
}

/**
 * @param non-empty-string $key
 */
function withDynamicKey(string $key): void
{
    // A dynamic key hides which variable is set.
    view('signed-template')->with($key, 'value');
}
