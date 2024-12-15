<?php

declare(strict_types=1);

namespace LaravelViewFunction;

use function view;
use Bladestan\Tests\Rules\Source\SomeDto;

view('foo', new SomeDto());

$data = new SomeDto();
view('foo', $data);
