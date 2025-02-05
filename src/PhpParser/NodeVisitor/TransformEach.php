<?php

declare(strict_types=1);

namespace Bladestan\PhpParser\NodeVisitor;

use Illuminate\Support\Arr;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Echo_;
use PhpParser\Node\Stmt\Else_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\If_;
use PhpParser\NodeVisitorAbstract;

final class TransformEach extends NodeVisitorAbstract
{
    public function enterNode(Node $node): null|If_|Foreach_
    {
        if (! $node instanceof Echo_) {
            return null;
        }

        $expr = $node->exprs[0];
        if (! $expr instanceof MethodCall || ! $this->isEach($expr)) {
            return null;
        }

        if (count($expr->args) < 3) {
            return null;
        }

        assert($expr->args[0] instanceof Arg);

        $iterator = $expr->args[1];
        if (! $iterator instanceof Arg) {
            return null;
        }

        $valueName = $expr->args[2];
        if (! $valueName instanceof Arg || ! $valueName->value instanceof String_) {
            return null;
        }

        $valueName = $valueName->value->value;
        $foreach = new Foreach_(
            $iterator->value,
            new Variable($valueName),
            [
                'keyVar' => new Variable('key'),
                'stmts' => [$this->makeMake($expr->args[0], [
                    new ArrayItem(new Variable('key'), new String_('key')),
                    new ArrayItem(new Variable($valueName), new String_($valueName)),
                ])],
            ]
        );

        if (count($expr->args) === 3) {
            return $foreach;
        }

        if (! $expr->args[3] instanceof Arg) {
            return null;
        }

        if ($expr->args[3]->value instanceof String_ && str_starts_with($expr->args[3]->value->value, 'raw|')) {
            $else = new Echo_([new String_(substr($expr->args[3]->value->value, 4))]);
        } else {
            $else = $this->makeMake($expr->args[3]);
        }

        return new If_(
            new FuncCall(new Name('count'), [new Arg($iterator->value)]),
            [
                'stmts' => [$foreach],
                'else' => new Else_([$else]),
            ]
        );
    }

    /**
     * @param list<ArrayItem> $args
     */
    private function makeMake(Arg $arg, array $args = []): Echo_
    {
        return new Echo_([
            new MethodCall(
                new MethodCall(
                    new Variable('__env'),
                    'make',
                    [
                        $arg,
                        new Arg(new Array_($args)),
                        new Arg(
                            new StaticCall(
                                new Name('\\' . Arr::class),
                                'except',
                                [
                                    new Arg(new FuncCall(new Name('get_defined_vars'))),
                                    new Arg(new Array_([
                                        new ArrayItem(new String_('__data')),
                                        new ArrayItem(new String_('__path')),
                                    ])),
                                ]
                            )
                        ),
                    ]
                ),
                'render'
            ),
        ]);
    }

    private function isEach(MethodCall $methodCall): bool
    {
        return $methodCall->var instanceof Variable &&
            $methodCall->var->name === '__env' &&
            $methodCall->name instanceof Identifier &&
            $methodCall->name->name === 'renderEach';
    }
}
