<?php

declare(strict_types=1);

namespace Bladestan\NodeAnalyzer;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;

final class ViewDataParametersAnalyzer
{
    public function __construct(
        private readonly CompactFunctionCallParameterResolver $compactFunctionCallParameterResolver,
        private readonly ViewVariableAnalyzer $viewVariableAnalyzer,
    ) {
    }

    public function resolveParametersArray(Arg $arg, Scope $scope): Array_
    {
        $secondArgValue = $arg->value;

        if ($secondArgValue instanceof Variable || $secondArgValue instanceof New_) {
            return $this->viewVariableAnalyzer->resolve($secondArgValue, $scope);
        }

        if ($secondArgValue instanceof Array_) {
            return $secondArgValue;
        }

        if ($secondArgValue instanceof FuncCall && $secondArgValue->name instanceof Name) {
            $funcName = $scope->resolveName($secondArgValue->name);

            if ($funcName === 'compact') {
                return $this->compactFunctionCallParameterResolver->resolveParameters($secondArgValue);
            }
        }

        return new Array_();
    }
}
