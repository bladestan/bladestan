<?php

declare(strict_types=1);

namespace Bladestan\NodeAnalyzer;

use Bladestan\ValueObject\ResolvedParameters;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use ValueError;

final class ViewDataParametersAnalyzer
{
    public function __construct(
        private readonly CompactFunctionCallParameterResolver $compactFunctionCallParameterResolver,
        private readonly ViewVariableAnalyzer $viewVariableAnalyzer,
        private readonly TemplateVariableTypesResolver $templateVariableTypesResolver,
    ) {
    }

    /**
     * Resolve a render site's data argument to the variables it passes. The
     * result is unresolved when the argument's array shape can't be fully
     * determined (e.g. `array_merge()`, a typed parameter, a ternary): see
     * {@see ResolvedParameters} for why callers must then skip the
     * missing-parameter check rather than treat it as "no data was passed".
     *
     * @throws ValueError
     */
    public function resolveParametersArray(Arg $arg, Scope $scope): ResolvedParameters
    {
        $secondArgValue = $arg->value;

        if ($secondArgValue instanceof Array_) {
            return $this->templateVariableTypesResolver->resolveArray($secondArgValue, $scope);
        }

        if ($secondArgValue instanceof FuncCall && $secondArgValue->name instanceof Name) {
            $funcName = $scope->resolveName($secondArgValue->name);

            if ($funcName === 'compact') {
                return $this->compactFunctionCallParameterResolver->resolveParameters($secondArgValue, $scope);
            }
        }

        // Anything else (a typed variable, `array_merge()`, a ternary, a
        // method call, ...) is resolved generally through the expression's
        // PHPStan type rather than assumed empty.
        return $this->viewVariableAnalyzer->resolve($secondArgValue, $scope);
    }
}
