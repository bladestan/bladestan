<?php

declare(strict_types=1);

namespace ViewCallSiteNoSignature;

use function view;

view('foo', [
    'foo' => 'bar',
]);