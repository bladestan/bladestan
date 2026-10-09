<?php

declare(strict_types=1);

namespace Bladestan\ValueObject;

use PHPStan\Type\Type;

/**
 * The variables a render site passes to a template, resolved from its data
 * argument.
 *
 * `$resolved` is false when the argument's array shape can't be fully
 * determined (e.g. `array_merge()`, a typed parameter, a ternary, a `compact()`
 * with a non-literal name), as opposed to a genuinely empty array. Callers must
 * not treat an unresolved result as "no data was passed": the opaque value may
 * supply any of the signature's variables, so the missing-parameter check has
 * to be skipped rather than reporting a false positive.
 */
final class ResolvedParameters
{
    /**
     * @param array<string, Type> $parameters
     */
    public function __construct(
        public readonly array $parameters,
        public readonly bool $resolved = true,
    ) {
    }
}
