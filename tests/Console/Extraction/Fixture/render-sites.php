<?php

declare(strict_types=1);

namespace BladestanGenerateFixture;

use App\Models\User;

use function view;

// Two sites of the same view: $title is passed as both string and int (their
// union), and $user is passed by only one site (so it becomes nullable).
view('signed-template', [
    'title' => 'Hello World',
    'user' => new User(),
]);

view('signed-template', [
    'title' => 123,
]);

function mixedValue(): mixed
{
    return null;
}

// Two sites of view 'bar': one passes a concrete User, the other a value
// PHPStan can only see as mixed. The mixed site must not widen $thing to
// App\Models\User|mixed (which collapses to a useless bare mixed); the concrete
// type wins.
view('bar', [
    'thing' => new User(),
]);

view('bar', [
    'thing' => mixedValue(),
]);
