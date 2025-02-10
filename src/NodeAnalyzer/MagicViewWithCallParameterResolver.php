<?php

declare(strict_types=1);

namespace Bladestan\NodeAnalyzer;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\CallLike;
use PHPStan\Analyser\Scope;
use PHPStan\Type\Type;

final class MagicViewWithCallParameterResolver
{
    /**
     * @return array<string, Type>
     */
    public function resolve(CallLike $callLike, Scope $scope): array
    {
        $result = [];

        if (! $callLike->hasAttribute('viewWithArgs')) {
            return $result;
        }

        /** @var array<string, Expr> */
        $viewWithArgs = $callLike->getAttribute('viewWithArgs');

        foreach ($viewWithArgs as $variableName => $args) {
            $result[$variableName] = $scope->getType($args);
        }

        return $result;
    }
}
