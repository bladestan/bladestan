<?php

declare(strict_types=1);

namespace Bladestan\NodeAnalyzer;

use Bladestan\ValueObject\ResolvedParameters;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\CallLike;
use PHPStan\Analyser\Scope;

final class MagicViewWithCallParameterResolver
{
    /**
     * Resolve the data a view's `->with(...)` magic calls pass. The result is
     * marked unresolved when {@see \Bladestan\PhpParser\NodeVisitor\ViewFunctionArgumentsNodeVisitor}
     * saw a `->with()` whose shape it couldn't read (e.g. `->with(compact(...))`),
     * so the caller skips the missing-parameter check instead of treating the
     * unseen data as "nothing passed".
     */
    public function resolve(CallLike $callLike, Scope $scope): ResolvedParameters
    {
        $result = [];

        if ($callLike->hasAttribute('viewWithArgs')) {
            /** @var array<string, Expr> $viewWithArgs */
            $viewWithArgs = $callLike->getAttribute('viewWithArgs');
            foreach ($viewWithArgs as $variableName => $args) {
                $result[$variableName] = $scope->getType($args);
            }
        }

        $resolved = $callLike->getAttribute('viewWithUnresolved', false) !== true;

        return new ResolvedParameters($result, $resolved);
    }
}
