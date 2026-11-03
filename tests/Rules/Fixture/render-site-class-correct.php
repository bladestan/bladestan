<?php

declare(strict_types=1);

namespace RenderSiteClassCorrect;

use Bladestan\Tests\Rules\Source\SomeViewResponse;

new SomeViewResponse('signed-template', [
    'title' => 'Hello World',
    'user' => new \App\Models\User(),
]);
