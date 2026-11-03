<?php

declare(strict_types=1);

namespace RenderSiteClassSubclass;

use Bladestan\Tests\Rules\Source\SomePdfViewResponse;

new SomePdfViewResponse('signed-template', [
    'title' => 1,
    'user' => new \App\Models\User(),
]);
