<?php

declare(strict_types=1);

namespace RenderSiteClassWrongType;

use Bladestan\Tests\Rules\Source\SomeViewResponse;

new SomeViewResponse('signed-template', [
    'title' => 1,
    'user' => new \App\Models\User(),
]);
