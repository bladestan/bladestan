<?php

declare(strict_types=1);

use Bladestan\Tests\Rules\Source\SomeObject;

view('view-render/some_int', [
    'number' => 500,
    'someObject' => new SomeObject(),
]);
