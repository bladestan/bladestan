<?php

declare(strict_types=1);

namespace Bladestan\NodeAnalyzer;

use Bladestan\ValueObject\ResolvedParameters;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PHPStan\Analyser\Scope;

final class TemplateVariableTypesResolver
{
    /**
     * Resolve an array literal to a variable-name-to-type map. The result is
     * unresolved when an item can't be attributed to a known variable name (an
     * unpacked spread, or a non-constant key), so the array's full shape isn't
     * known.
     */
    public function resolveArray(Array_ $array, Scope $scope): ResolvedParameters
    {
        $variableNamesToTypes = [];
        $resolved = true;

        foreach ($array->items as $arrayItem) {
            if (! $arrayItem->key instanceof Expr) {
                $resolved = false;

                continue;
            }

            $arrayItemValue = $scope->getType($arrayItem->key);

            $keyName = $arrayItemValue->getConstantStrings();
            if (count($keyName) !== 1) {
                $resolved = false;

                continue;
            }

            $variableNamesToTypes[reset($keyName)->getValue()] = $scope->getType($arrayItem->value);
        }

        return new ResolvedParameters($variableNamesToTypes, $resolved);
    }
}
