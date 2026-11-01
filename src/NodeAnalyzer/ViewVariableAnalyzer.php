<?php

declare(strict_types=1);

namespace Bladestan\NodeAnalyzer;

use Bladestan\ValueObject\ResolvedParameters;
use Illuminate\Contracts\Support\Arrayable;
use PhpParser\Node\Expr;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Type\ObjectType;
use ValueError;

final class ViewVariableAnalyzer
{
    /**
     * Resolve the view data expression's type to a variable-name-to-type map.
     * Works for any expression, not just variables: a call's return type, a
     * ternary's union, and similar are all resolved the same way through
     * $scope->getType(). The result is unresolved when the expression's type
     * isn't a single constant array, so the shape (and therefore its variables)
     * can't be determined, as opposed to a genuinely empty array.
     *
     * @throws ValueError
     */
    public function resolve(Expr $expr, Scope $scope): ResolvedParameters
    {
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
            return new ResolvedParameters([], false);
        }

        $constantArray = $constantArrays[0];
        $optionalKeys = $constantArray->getOptionalKeys();
        $valueTypes = $constantArray->getValueTypes();

        // An optional key (`array{user?: User}`) may be absent at runtime, so
        // it does not satisfy a required signature variable. Dropping it here
        // lets the missing-parameter check still fire for it, while the result
        // stays resolved so the guaranteed keys are validated normally.
        $keyNames = [];
        $keptValueTypes = [];
        foreach ($constantArray->getKeyTypes() as $index => $keyType) {
            if (in_array($index, $optionalKeys, true)) {
                continue;
            }

            $keyNames[] = (string) $keyType->getValue();
            $keptValueTypes[] = $valueTypes[$index];
        }

        return new ResolvedParameters(array_combine($keyNames, $keptValueTypes));
    }
}
