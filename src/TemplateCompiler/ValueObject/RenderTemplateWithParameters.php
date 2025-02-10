<?php

declare(strict_types=1);

namespace Bladestan\TemplateCompiler\ValueObject;

use PHPStan\Type\Type;

final class RenderTemplateWithParameters
{
    public function __construct(
        public readonly string $templateFilePath,
        /** @var array<string, Type> */
        public readonly array $parametersArray
    ) {
    }
}
