<?php

declare(strict_types=1);

namespace LaravelViewFunction;

use function view;

/**
 * @return array{bar: int}
 */
function barData(): array
{
    return ['bar' => 10];
}

$foo = 'bar';
view('foo', ['foo' => $foo] + barData());
view('foo', ['foo' => $foo, ...barData()]);
view('foo', barData() + compact('foo'));
