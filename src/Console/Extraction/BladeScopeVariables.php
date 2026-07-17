<?php

declare(strict_types=1);

namespace Bladestan\Console\Extraction;

use function in_array;
use function str_starts_with;

/**
 * Recognises the variables Blade injects into a compiled template's scope.
 *
 * A compiled template forwards its whole scope to `@include`d partials, but the
 * variables Blade adds itself (the environment, the error bag, component and
 * loop internals) are not part of any template's contract. They must be
 * excluded both when harvesting the types a partial receives and when detecting
 * the variables a partial actually needs, so a generated signature lists only
 * the real inputs.
 */
final class BladeScopeVariables
{
    /**
     * @var list<string>
     */
    private const INTERNAL_NAMES = [
        'loop',
        'errors',
        'component',
        'componentName',
        'attributes',
        'slot',
        'app',
        'message',
        'this',
        '_instance',
    ];

    /**
     * Everything Blade prefixes with `__` (`$__env`, `$__data`,
     * `$__currentLoopData`, `$__laravel_slots`, ...) plus the named internals.
     */
    public static function isInternal(string $name): bool
    {
        return str_starts_with($name, '__') || in_array($name, self::INTERNAL_NAMES, true);
    }
}
