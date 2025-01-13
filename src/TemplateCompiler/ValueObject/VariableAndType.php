<?php

declare(strict_types=1);

namespace Bladestan\TemplateCompiler\ValueObject;

use PHPStan\Type\ThisType;
use PHPStan\Type\Type;
use PHPStan\Type\VerbosityLevel;

final class VariableAndType
{
    public function __construct(
        public readonly string $variable,
        private readonly Type $type
    ) {
    }

    public function getTypeAsString(): string
    {
        if ($this->type instanceof ThisType) {
            return $this->type->getStaticObjectType()
                ->describe(VerbosityLevel::typeOnly());
        }

        return $this->type->describe(VerbosityLevel::typeOnly());
    }
}
