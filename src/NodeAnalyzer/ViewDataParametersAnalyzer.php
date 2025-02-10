<?php

declare(strict_types=1);

namespace Bladestan\NodeAnalyzer;

use Bladestan\TemplateCompiler\TypeAnalyzer\TemplateVariableTypesResolver;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Type\Type;

final class ViewDataParametersAnalyzer
{
    public function __construct(
        private readonly CompactFunctionCallParameterResolver $compactFunctionCallParameterResolver,
        private readonly ViewVariableAnalyzer $viewVariableAnalyzer,
        private readonly TemplateVariableTypesResolver $templateVariableTypesResolver,
    ) {
    }

    /**
     * @return array<string, Type>
     */
    public function resolveParametersArray(Arg $arg, Scope $scope): array
    {
        $secondArgValue = $arg->value;

        if ($secondArgValue instanceof Variable || $secondArgValue instanceof New_) {
            return $this->viewVariableAnalyzer->resolve($secondArgValue, $scope);
        }

        if ($secondArgValue instanceof Array_) {
            return $this->templateVariableTypesResolver->resolveArray($secondArgValue, $scope);
        }

        if ($secondArgValue instanceof FuncCall && $secondArgValue->name instanceof Name) {
            $funcName = $scope->resolveName($secondArgValue->name);

            if ($funcName === 'compact') {
                return $this->compactFunctionCallParameterResolver->resolveParameters($secondArgValue, $scope);
            }
        }

        return [];
    }
}
