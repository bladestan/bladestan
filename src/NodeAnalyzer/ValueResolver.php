<?php

declare(strict_types=1);

namespace Bladestan\NodeAnalyzer;

use PhpParser\Node\Expr;
use PHPStan\Analyser\Scope;

final class ValueResolver
{
    public function resolve(Expr $expr, Scope $scope): mixed
    {
        $exprType = $scope->getType($expr);
        $constantScalarValues = $exprType->getConstantScalarValues();
        if (count($constantScalarValues) !== 1) {
            return null;
        }

        return $constantScalarValues[0];
    }
}
