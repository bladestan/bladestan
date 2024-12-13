<?php

declare(strict_types=1);

namespace TomasVotruba\Bladestan\Tests\Rules\Source;

use Illuminate\Contracts\Support\Arrayable;

/**
 * @implements Arrayable<string, string>
 */
class SomeDto implements Arrayable
{
    /**
     * @return array{foo: string}
     */
    public function toArray(): array
    {
        return [
            'foo' => 'bar',
        ];
    }
}
