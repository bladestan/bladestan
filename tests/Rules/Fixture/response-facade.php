<?php

declare(strict_types=1);

namespace LaravelViewFunction;

use Illuminate\Support\Facades\Response;

Response::view('simple_variable', ['foo' => 'bar']);
