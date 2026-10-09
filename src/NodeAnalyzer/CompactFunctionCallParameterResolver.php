<?php

declare(strict_types=1);

namespace Bladestan\NodeAnalyzer;

use Bladestan\ValueObject\ResolvedParameters;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;

final class CompactFunctionCallParameterResolver
{
    /**
     * Resolve a compact() call to a variable-name-to-type map. The result is
     * unresolved when an argument isn't a string literal, so the variable it
     * names can't be determined.
     */
    public function resolveParameters(FuncCall $compactFuncCall, Scope $scope): ResolvedParameters
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

        return new ResolvedParameters($resultArray, $resolved);
    }
}
