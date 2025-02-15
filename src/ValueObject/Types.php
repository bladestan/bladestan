<?php

declare(strict_types=1);

namespace Bladestan\ValueObject;

final class Types
{
    public static function getBool(): bool
    {
        return true;
    }

    public static function getInt(): int
    {
        return 1;
    }

    public static function getFloat(): float
    {
        return 1.0;
    }

    public static function getString(): string
    {
        return '';
    }

    /**
     * @return array<mixed>
     */
    public static function getArray(): array
    {
        return [];
    }

    public static function getMixed(): mixed
    {
        return null;
    }
}
