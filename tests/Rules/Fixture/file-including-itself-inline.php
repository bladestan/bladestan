<?php

declare(strict_types=1);

namespace LaravelViewFunction;

use function view;

view('file_including_itself_inline', [
    'foo' => 'foo',
    'children' => ['bar'],
]);
