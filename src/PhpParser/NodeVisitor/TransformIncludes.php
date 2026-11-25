<?php

declare(strict_types=1);

namespace Bladestan\PhpParser\NodeVisitor;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\Echo_;
use PhpParser\Node\Stmt\If_;
use PhpParser\NodeVisitorAbstract;

final class TransformIncludes extends NodeVisitorAbstract
{
    public function enterNode(Node $node): ?If_
    {
        if (! $node instanceof Echo_) {
            return null;
        }

        $expr = $node->exprs[0];
        if (! $expr instanceof MethodCall || ! $this->isWhen($expr)) {
            return null;
        }

        assert($expr->name instanceof Identifier);

        if (count($expr->args) < 3) {
            return null;
        }

        assert($expr->args[0] instanceof Arg);

        $condition = $expr->args[0]->value;

        if ($expr->name->name === 'renderUnless') {
            $condition = new BooleanNot($condition);
        }

        // Everything after the condition is the make() call Blade would emit for
        // the equivalent @include: the view name, any explicit data, and the
        // `array_diff_key(get_defined_vars(), ...)` scope-forwarding argument.
        // Forward all of them so TransformIncludesToViewCalls still sees the
        // forwarding arg; dropping it would make the partial's scope variables
        // look unprovided and false-positive as missing parameters.
        $makeArgs = array_slice($expr->args, 1);

        return new If_(
            $condition,
            [
                'stmts' => [new Echo_([
                    new MethodCall(
                        new MethodCall(new Variable('__env'), 'make', $makeArgs),
                        'render'
                    ),
                ])],
            ]
        );
    }

    private function isWhen(MethodCall $methodCall): bool
    {
        return $methodCall->var instanceof Variable &&
            $methodCall->var->name === '__env' &&
            $methodCall->name instanceof Identifier &&
            ($methodCall->name->name === 'renderWhen' || $methodCall->name->name === 'renderUnless');
    }
}
