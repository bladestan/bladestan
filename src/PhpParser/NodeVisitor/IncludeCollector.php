<?php

declare(strict_types=1);

namespace Bladestan\PhpParser\NodeVisitor;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Echo_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\If_;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter\Standard;

final class IncludeCollector extends NodeVisitorAbstract
{
    /**
     * @var list<array{string, string, string}>
     */
    private array $includes = [];

    /**
     * @return list<array{string, string, string}>
     */
    public function getIncludes(): array
    {
        return $this->includes;
    }

    public function enterNode(Node $node): null|If_|Foreach_
    {
        if (! $node instanceof Echo_ || ! $node->exprs[0] instanceof MethodCall) {
            return null;
        }

        $expr = $node->exprs[0]->var;
        if (! $expr instanceof MethodCall || ! $this->isMake($expr)) {
            return null;
        }

        if (count($expr->args) < 1) {
            return null;
        }

        $standard = new Standard();

        $node->setDocComment(new Doc(''));
        $nodeString = $standard->prettyPrint([$node]);

        assert($expr->args[0] instanceof Arg);
        $viewName = $expr->args[0];
        if (! $viewName->value instanceof String_) {
            return null;
        }

        $viewName = $viewName->value->value;

        $viewData = '';
        if (count($expr->args) > 1) {
            assert($expr->args[1] instanceof Arg);
            $viewValue = $expr->args[1]->value;
            if ($viewValue instanceof Array_ || $viewValue instanceof Variable) {
                $viewData = $standard->prettyPrint([$viewValue]);
            }
        }

        $this->includes[] = [$nodeString, $viewName, $viewData];

        return null;
    }

    private function isMake(MethodCall $methodCall): bool
    {
        return $methodCall->var instanceof Variable &&
            $methodCall->var->name === '__env' &&
            $methodCall->name instanceof Identifier &&
            $methodCall->name->name === 'make';
    }
}
