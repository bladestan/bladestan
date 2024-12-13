<?php

declare(strict_types=1);

namespace TomasVotruba\Bladestan\NodeAnalyzer;

use Illuminate\Contracts\Support\Arrayable;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\VerbosityLevel;
use TomasVotruba\Bladestan\TemplateCompiler\NodeFactory\VarDocNodeFactory;

final class ViewVariableAnalyzer
{
    /**
     * Resolve view function call if the data is a variable.
     */
    public function resolve(Expr $expr, Scope $scope): Array_
    {
        $parametersArray = new Array_();

        $type = $scope->getType($expr);

        /** @phpstan-ignore phpstanApi.instanceofType */
        if ($type instanceof ObjectType) {
            if (! $type->isInstanceOf(Arrayable::class)->yes()) {
                return $parametersArray;
            }

            $type = $type->getMethod('toArray', $scope)
                ->getVariants()[0]
                ->getReturnType();
        }

        /** @phpstan-ignore phpstanApi.instanceofType */
        if (! $type instanceof ConstantArrayType) {
            return $parametersArray;
        }

        $keyTypes = array_map(function ($keyType): string {
            return (string) $keyType->getValue();
        }, $type->getKeyTypes());

        foreach (array_combine($keyTypes, $type->getValueTypes()) as $key => $value) {
            VarDocNodeFactory::setDocBlock($key, $value->describe(VerbosityLevel::typeOnly()));
            $parametersArray->items[] = new ArrayItem(new Variable($key), new String_($key));
        }

        return $parametersArray;
    }
}
