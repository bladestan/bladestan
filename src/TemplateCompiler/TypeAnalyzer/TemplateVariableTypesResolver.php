<?php

declare(strict_types=1);

namespace Bladestan\TemplateCompiler\TypeAnalyzer;

use Bladestan\TemplateCompiler\ValueObject\VariableAndType;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PHPStan\Analyser\Scope;

final class TemplateVariableTypesResolver
{
    /**
     * @return list<VariableAndType>
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
            if ($keyName === []) {
                continue;
            }

            $variableType = $scope->getType($arrayItem->value);
            $variableNamesToTypes[] = new VariableAndType(reset($keyName)->getValue(), $variableType);
        }

        return $variableNamesToTypes;
    }
}
