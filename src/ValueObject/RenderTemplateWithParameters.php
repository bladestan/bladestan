<?php

declare(strict_types=1);

namespace Bladestan\ValueObject;

use PHPStan\Type\Type;

final class RenderTemplateWithParameters
{
    public function __construct(
        public readonly string $templateName,
        /**
         * @var array<string, Type>
         */
        public readonly array $parametersArray,
        /**
         * True when the call site forwards the surrounding scope to the
         * template (`view($name, $data, get_defined_vars())`), which is how
         * compiled `@include` calls mirror Blade's runtime behaviour.
         * Variables from the caller's scope may then satisfy the template's
         * signature.
         */
        public readonly bool $forwardsScope = false,
    ) {
    }
}
