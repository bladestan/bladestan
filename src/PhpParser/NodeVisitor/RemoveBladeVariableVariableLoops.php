<?php

declare(strict_types=1);

namespace Bladestan\PhpParser\NodeVisitor;

use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Unset_;
use PhpParser\NodeFinder;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;

/**
 * `@props` and `@aware` compile to loops that write template variables by a
 * runtime name (`$$__key = $$__key ?? $__value;`, `unset($$__key);`). PHPStan
 * cannot know which variable such a write touches, so it widens every variable
 * in scope to `mixed`, wiping out the types the signature and component scope
 * declared above the loop. Those declarations already type what the loops
 * define, so the loops carry nothing the analysis can use and are removed.
 *
 * Only loops keyed on Blade's own `$__`-prefixed internals are matched, so a
 * variable variable the template author wrote is still analysed.
 */
final class RemoveBladeVariableVariableLoops extends NodeVisitorAbstract
{
    private readonly NodeFinder $nodeFinder;

    public function __construct()
    {
        $this->nodeFinder = new NodeFinder();
    }

    public function leaveNode(Node $node): ?int
    {
        if (! $node instanceof Foreach_) {
            return null;
        }

        $write = $this->nodeFinder->findFirst($node->stmts, function (Node $node): bool {
            if ($node instanceof Assign || $node instanceof AssignOp || $node instanceof AssignRef) {
                return $this->isBladeVariableVariable($node->var);
            }

            if ($node instanceof Unset_) {
                foreach ($node->vars as $var) {
                    if ($this->isBladeVariableVariable($var)) {
                        return true;
                    }
                }
            }

            return false;
        });

        return $write instanceof Node ? NodeVisitor::REMOVE_NODE : null;
    }

    private function isBladeVariableVariable(Node $node): bool
    {
        return $node instanceof Variable
            && $node->name instanceof Variable
            && is_string($node->name->name)
            && str_starts_with($node->name->name, '__');
    }
}
