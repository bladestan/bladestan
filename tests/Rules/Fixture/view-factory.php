<?php

declare(strict_types=1);

namespace ViewFactory;

use Illuminate\View\Factory;

use function random_int;

/** @var Factory $factory */

$factory->make('foo', [
    'foo' => 'bar',
]);
$factory->first(['404', 'foo'], [
    'foo' => 'bar',
]);
$factory->renderEach('foo', ['bar'], 'foo');
$factory->renderWhen(random_int(0, 100) > 50, 'foo', [
    'foo' => 'bar',
]);
$factory->renderUnless(random_int(0, 100) > 50, 'foo', [
    'foo' => 'bar',
]);
