<?php

declare(strict_types=1);

namespace Bladestan\PhpParser\NodeVisitor;

use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Echo_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

final class RemoveEnvVariableNodeVisitor extends NodeVisitorAbstract
{
    public function leaveNode(Node $node): ?int
    {
        // Remove `$__env->...(...)`
        if (
            ($node instanceof Expression) && $this->isEnvCall($node->expr) ||
            (($node instanceof Echo_) && $this->isEnvCall($node->exprs[0]))
        ) {
            return NodeTraverser::REMOVE_NODE;
        }

        if (! $node instanceof Expression) {
            return null;
        }

        if (! $node->expr instanceof Assign) {
            return null;
        }

        if (! $node->expr->var instanceof Variable) {
            return null;
        }

        if ($node->expr->var->name !== 'loop') {
            return null;
        }

        return NodeTraverser::REMOVE_NODE;
    }

    private function isEnvCall(Node $node): bool
    {
        return $node instanceof MethodCall &&
            $node->var instanceof Variable &&
            $node->var->name === '__env';
    }
}
