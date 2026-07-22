<?php

declare(strict_types=1);

namespace Bladestan\NodeAnalyzer;

use Illuminate\Contracts\Support\Arrayable;
use PhpParser\Node\Expr;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use ValueError;

final class ViewVariableAnalyzer
{
    /**
     * Resolve the view data expression's type to a variable-name-to-type map.
     * Works for any expression, not just variables: a call's return type, a
     * ternary's union, and similar are all resolved the same way through
     * $scope->getType().
     *
     * @param bool $resolved Set to false when the expression's type isn't a
     * single constant array, so the shape (and therefore its variables)
     * can't be determined — as opposed to a genuinely empty array.
     *
     * @return array<string, Type>
     *
     * @throws ValueError
     */
    public function resolve(Expr $expr, Scope $scope, bool &$resolved = true): array
    {
        $parametersArray = [];
        $resolved = true;

        $type = $scope->getType($expr);

        $objectType = new ObjectType(Arrayable::class);
        if ($objectType->isSuperTypeOf($type)->yes()) {
            $extendedMethodReflection = $type->getMethod('toArray', $scope);
            $type = ParametersAcceptorSelector::selectFromArgs(
                $scope,
                [],
                $extendedMethodReflection->getVariants()
            )->getReturnType();
        }

        $constantArrays = $type->getConstantArrays();

        if (count($constantArrays) !== 1) {
            $resolved = false;

            return $parametersArray;
        }

        $keyTypes = array_map(function (ConstantIntegerType|ConstantStringType $keyType): string {
            return (string) $keyType->getValue();
        }, $constantArrays[0]->getKeyTypes());

        return array_combine($keyTypes, $constantArrays[0]->getValueTypes());
    }
}
