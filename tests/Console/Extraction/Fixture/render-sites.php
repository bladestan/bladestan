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

// Two sites of view 'static_content' pass generics that carry a union inside
// their type arguments, sharing the trailing `int>` segment. The merge must
// union them at the top level only; splitting on every `|` would dedup that
// shared segment away and corrupt the type.
/** @var array<int, User|int> $arrayItems */
$arrayItems = [];
view('static_content', [
    'items' => $arrayItems,
]);

/** @var \Illuminate\Support\Collection<int, string|int> $collectionItems */
$collectionItems = new \Illuminate\Support\Collection();
view('static_content', [
    'items' => $collectionItems,
]);
