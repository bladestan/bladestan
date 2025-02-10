<?php

declare(strict_types=1);

namespace Bladestan\NodeAnalyzer;

use Illuminate\Contracts\Support\Arrayable;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Variable;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

final class ViewVariableAnalyzer
{
    /**
     * Resolve view function call if the data is a variable.
     *
     * @return array<string, Type>
     */
    public function resolve(Expr $expr, Scope $scope): array
    {
        $parametersArray = [];

        $type = $scope->getType($expr);

        $objectType = new ObjectType(Arrayable::class);
        if (! $objectType->isSuperTypeOf($type)->yes()) {
            return $parametersArray;
        }

        $extendedMethodReflection = $type->getMethod('toArray', $scope);
        $type = ParametersAcceptorSelector::selectFromArgs(
            $scope,
            [],
            $extendedMethodReflection->getVariants()
        )->getReturnType();

        $constantArrays = $type->getConstantArrays();

        if (count($constantArrays) !== 1) {
            return $parametersArray;
        }

        $keyTypes = array_map(function ($keyType): string {
            return (string) $keyType->getValue();
        }, $constantArrays[0]->getKeyTypes());

        return array_combine($keyTypes, $constantArrays[0]->getValueTypes());
    }
}
