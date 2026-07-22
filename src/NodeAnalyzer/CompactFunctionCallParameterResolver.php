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
     * @param bool $resolved Set to false when a compact() argument isn't a
     * string literal, so the variable it names can't be determined.
     *
     * @return array<string, Type>
     */
    public function resolveParameters(FuncCall $compactFuncCall, Scope $scope, bool &$resolved = true): array
    {
        $resultArray = [];
        $resolved = true;

        $funcArgs = $compactFuncCall->getArgs();

        foreach ($funcArgs as $funcArg) {
            if (! $funcArg->value instanceof String_) {
                $resolved = false;

                continue;
            }

            $variableName = $funcArg->value->value;

            $resultArray[$variableName] = $scope->getType(new Variable($variableName));
        }

        return $resultArray;
    }
}
