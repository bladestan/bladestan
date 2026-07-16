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
