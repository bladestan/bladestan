<?php

declare(strict_types=1);

namespace ViewCallSiteUnresolvableData;

use App\Models\User;

use function view;

/**
 * @param array<string, mixed> $data
 */
function withTypedArray(array $data): void
{
    view('signed-template', $data);
}

function withArrayMerge(): void
{
    view('signed-template', array_merge(['title' => 'Hello World'], ['user' => new User()]));
}
