<?php

declare(strict_types=1);

namespace Bladestan\NodeAnalyzer;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Type\Type;
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
     * @param bool $resolved Set to false when the argument's array shape
     * can't be fully determined (e.g. `array_merge()`, a typed parameter, a
     * ternary), as opposed to a genuinely empty array. Callers must not treat
     * an unresolved result as "no data was passed" — the missing-parameter
     * check has to be skipped instead, since the opaque value may supply any
     * of the signature's variables.
     *
     * @return array<string, Type>
     *
     * @throws ValueError
     */
    public function resolveParametersArray(Arg $arg, Scope $scope, bool &$resolved = true): array
    {
        $secondArgValue = $arg->value;

        if ($secondArgValue instanceof Array_) {
            return $this->templateVariableTypesResolver->resolveArray($secondArgValue, $scope, $resolved);
        }

        if ($secondArgValue instanceof FuncCall && $secondArgValue->name instanceof Name) {
            $funcName = $scope->resolveName($secondArgValue->name);

            if ($funcName === 'compact') {
                return $this->compactFunctionCallParameterResolver->resolveParameters(
                    $secondArgValue,
                    $scope,
                    $resolved,
                );
            }
        }

        // Anything else (a typed variable, `array_merge()`, a ternary, a
        // method call, ...) is resolved generally through the expression's
        // PHPStan type rather than assumed empty.
        return $this->viewVariableAnalyzer->resolve($secondArgValue, $scope, $resolved);
    }
}
