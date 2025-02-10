<?php

declare(strict_types=1);

namespace Bladestan\NodeAnalyzer;

use Illuminate\Support\Str;
use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Scalar\String_;
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

        /** @var array<string, Node\Arg[]> $viewWithArgs */
        $viewWithArgs = $callLike->getAttribute('viewWithArgs');

        foreach ($viewWithArgs as $variableName => $args) {
            if ($variableName === 'with' && $args[0]->value instanceof String_) {
                $result[$args[0]->value->value] = $scope->getType($args[1]->value);
            } elseif (str_starts_with($variableName, 'with')) {
                $result[Str::camel(substr($variableName, 4))] = $scope->getType($args[0]->value);
            }
        }

        return $result;
    }
}
