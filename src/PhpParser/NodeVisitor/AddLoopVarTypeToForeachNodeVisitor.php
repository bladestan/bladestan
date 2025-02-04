<?php

declare(strict_types=1);

namespace Bladestan\PhpParser\NodeVisitor;

use Bladestan\ValueObject\Loop;
use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\NodeVisitorAbstract;

final class AddLoopVarTypeToForeachNodeVisitor extends NodeVisitorAbstract
{
    /**
     * @var array<bool>
     */
    private array $loopStack = [];

    public function enterNode(Node $node): Node
    {
        if ($node instanceof Foreach_) {
            $this->loopStack[] = true;
        }

        return $node;
    }

    public function leaveNode(Node $node): ?Expression
    {
        if ($node instanceof Foreach_) {
            array_pop($this->loopStack);
        }

        if ($this->isLoopAssignment($node)) {
            if ($this->loopStack !== []) {
                return new Expression(new Assign(new Variable('loop'), new New_(new FullyQualified(Loop::class))));
            }

            return new Expression(new Assign(new Variable('loop'), new ConstFetch(new Name('null'))));
        }

        return null;
    }

    private function isLoopAssignment(Node $node): bool
    {
        return $node instanceof Expression &&
            $node->expr instanceof Assign &&
            $node->expr->var instanceof Variable &&
            $node->expr->var->name === 'loop' &&
            $node->expr->expr instanceof MethodCall &&
            $node->expr->expr->var instanceof Variable &&
            $node->expr->expr->var->name === '__env' &&
            $node->expr->expr->name instanceof Identifier &&
            $node->expr->expr->name->name === 'getLastLoop';
    }
}
