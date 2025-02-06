<?php

declare(strict_types=1);

namespace Bladestan\TemplateCompiler\ValueObject;

final class RenderTemplateWithParameters
{
    public function __construct(
        public readonly string $templateFilePath,
        /** @var list<VariableAndType> */
        public readonly array $parametersArray
    ) {
    }
}
