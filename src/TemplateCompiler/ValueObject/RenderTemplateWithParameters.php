<?php

declare(strict_types=1);

namespace Bladestan\TemplateCompiler\ValueObject;

use PhpParser\Node\Expr\Array_;

final class RenderTemplateWithParameters
{
    public function __construct(
        public readonly string $templateFilePath,
        public readonly Array_ $parametersArray
    ) {
    }
}
