<?php

declare(strict_types=1);

namespace Bladestan\PhpParser\NodeVisitor;

use Bladestan\ValueObject\Loop;
use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Unset_;
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

    /**
     * @return Node[]|null
     */
    public function leaveNode(Node $node): ?array
    {
        if (! $node instanceof Foreach_) {
            return null;
        }

        array_pop($this->loopStack);
        if ($this->loopStack !== []) {
            return null;
        }

        if (! $node->expr instanceof Variable) {
            return null;
        }

        $foreachedVariableName = $node->expr->name;

        if (! is_string($foreachedVariableName)) {
            return null;
        }

        if ($foreachedVariableName !== '__currentLoopData') {
            return null;
        }

        $expression = new Expression(new Assign(new Variable('loop'), new New_(new FullyQualified(Loop::class))));

        // Add `$loop` var as the first statement
        array_unshift($node->stmts, $expression);

        // `endforeach` also has a doc comment. Remove that before adding our unset.
        array_pop($node->stmts);

        // Add `unset($loop)` at the end of the loop
        // to prevent accessing this variable outside of loop
        $node->stmts[] = new Unset_([new Variable('loop')]);

        return null;
    }
}
