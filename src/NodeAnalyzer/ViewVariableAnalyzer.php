<?php

declare(strict_types=1);

namespace Bladestan\NodeAnalyzer;

use Bladestan\TemplateCompiler\NodeFactory\VarDocNodeFactory;
use Illuminate\Contracts\Support\Arrayable;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Type\ObjectType;
use PHPStan\Type\VerbosityLevel;

final class ViewVariableAnalyzer
{
    /**
     * Resolve view function call if the data is a variable.
     */
    public function resolve(Expr $expr, Scope $scope): Array_
    {
        $parametersArray = new Array_();

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

        foreach (array_combine($keyTypes, $constantArrays[0]->getValueTypes()) as $key => $value) {
            VarDocNodeFactory::setDocBlock($key, $value->describe(VerbosityLevel::typeOnly()));
            $parametersArray->items[] = new ArrayItem(new Variable($key), new String_($key));
        }

        return $parametersArray;
    }
}
