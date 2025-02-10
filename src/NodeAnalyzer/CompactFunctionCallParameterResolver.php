<?php

declare(strict_types=1);

namespace Bladestan\NodeAnalyzer;

use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Type\Type;

final class CompactFunctionCallParameterResolver
{
    /**
     * @return array<string, Type>
     */
    public function resolveParameters(FuncCall $compactFuncCall, Scope $scope): array
    {
        $resultArray = [];

        $funcArgs = $compactFuncCall->getArgs();

        foreach ($funcArgs as $funcArg) {
            if (! $funcArg->value instanceof String_) {
                continue;
            }

            $variableName = $funcArg->value->value;

            $resultArray[$variableName] = $scope->getType(new Variable($variableName));
        }

        return $resultArray;
    }
}
