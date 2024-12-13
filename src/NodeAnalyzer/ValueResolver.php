<?php

declare(strict_types=1);

namespace TomasVotruba\Bladestan\NodeAnalyzer;

use PhpParser\ConstExprEvaluationException;
use PhpParser\ConstExprEvaluator;
use PhpParser\Node\Expr;
use PHPStan\Analyser\Scope;
use PHPStan\Type\ConstantScalarType;

final class ValueResolver
{
    public function __construct(
        private readonly ConstExprEvaluator $constExprEvaluator
    ) {
    }

    public function resolve(Expr $expr, Scope $scope): mixed
    {
        try {
            return $this->constExprEvaluator->evaluateDirectly($expr);
        } catch (ConstExprEvaluationException) {
        }

        $exprType = $scope->getType($expr);

        /** @phpstan-ignore phpstanApi.instanceofType */
        if ($exprType instanceof ConstantScalarType) {
            return $exprType->getValue();
        }

        return null;
    }
}
