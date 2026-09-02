<?php

declare(strict_types=1);

namespace ViewCallSiteScopeForwarding;

use App\Models\User;

use function view;

// This mirrors what a compiled @include emits: explicit data plus the
// surrounding scope forwarded as view()'s $mergeData parameter.

// Scope satisfies the whole signature — no explicit data needed.
$title = 'Hello World';
$user = new User();
view('signed-template', [], get_defined_vars());

// Explicit data wins over scope; the rest still comes from scope.
view('signed-template', [
    'title' => 'Explicit',
], get_defined_vars());

// A scope variable with the wrong type is a type error, not a missing one.
$title = 42;
view('signed-template', [], get_defined_vars());

// A signature variable that is neither passed nor in scope is still missing.
unset($user);
view('signed-template', [
    'title' => 'Hello World',
], get_defined_vars());

// Without the forwarding argument, scope variables do not count.
$user = new User();
view('signed-template', [
    'user' => new User(),
]);

// A standalone @var docblock (what compiled templates emit for their own
// signature variables) defines the variable in scope.
/** @var string $title */
view('signed-template', [], get_defined_vars());
