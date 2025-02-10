<?php

declare(strict_types=1);

namespace Bladestan\TemplateCompiler\TypeAnalyzer;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PHPStan\Analyser\Scope;
use PHPStan\Type\Type;

final class TemplateVariableTypesResolver
{
    /**
     * @return array<string, Type>
     */
    public function resolveArray(Array_ $array, Scope $scope): array
    {
        $variableNamesToTypes = [];

        foreach ($array->items as $arrayItem) {
            if (! $arrayItem->key instanceof Expr) {
                continue;
            }

            $arrayItemValue = $scope->getType($arrayItem->key);

            $keyName = $arrayItemValue->getConstantStrings();
            if (count($keyName) !== 1) {
                continue;
            }

            $variableNamesToTypes[reset($keyName)->getValue()] = $scope->getType($arrayItem->value);
        }

        return $variableNamesToTypes;
    }
}
