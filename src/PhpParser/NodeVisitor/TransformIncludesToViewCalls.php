<?php

declare(strict_types=1);

namespace Bladestan\PhpParser\NodeVisitor;

use Illuminate\Support\Arr;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt\Echo_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\NodeVisitorAbstract;

/**
 * Transforms compiled `@include` directives into `view()` calls.
 *
 * Blade compiles `@include('partials.header', ['title' => $title])` into:
 *
 *     echo $__env->make('partials.header', ['title' => $title], array_diff_key(get_defined_vars(), ...))->render();
 *
 * This visitor rewrites it to:
 *
 *     view('partials.header', ['title' => $title]);
 *
 * The resulting `view()` call is validated by `ViewCallSiteRule` against the
 * included template's signature — `@include` is a call site, exactly like a
 * `view()` call in PHP source.
 *
 * Blade's implicit scope-forwarding argument (`array_diff_key(get_defined_vars(), ...)`
 * for includes, `Arr::except(get_defined_vars(), ...)` for extends) is dropped:
 * if a partial needs a variable, its signature must declare it and the
 * `@include` must pass it explicitly.
 *
 * Must run in a separate traversal AFTER `TransformEach` and `TransformIncludes`,
 * which produce the `echo $__env->make(...)->render()` statements this visitor matches.
 */
final class TransformIncludesToViewCalls extends NodeVisitorAbstract
{
    public function enterNode(Node $node): ?Expression
    {
        if (! $node instanceof Echo_) {
            return null;
        }

        $expr = $node->exprs[0];
        if (! $expr instanceof MethodCall
            || ! $expr->name instanceof Identifier
            || $expr->name->name !== 'render'
        ) {
            return null;
        }

        $make = $expr->var;
        if (! $make instanceof MethodCall || ! $this->isEnvMake($make)) {
            return null;
        }

        if (! isset($make->args[0]) || ! $make->args[0] instanceof Arg) {
            return null;
        }

        $args = [$make->args[0]];
        if (isset($make->args[1])
            && $make->args[1] instanceof Arg
            && ! $this->isScopeForwardingArg($make->args[1]->value)
        ) {
            $args[] = $make->args[1];
        }

        $expression = new Expression(new FuncCall(new Name('view'), $args));
        $expression->setAttributes($node->getAttributes());

        return $expression;
    }

    private function isEnvMake(MethodCall $methodCall): bool
    {
        return $methodCall->var instanceof Variable
            && $methodCall->var->name === '__env'
            && $methodCall->name instanceof Identifier
            && $methodCall->name->name === 'make';
    }

    /**
     * Blade forwards the parent scope via `array_diff_key(get_defined_vars(), ...)`
     * (includes) or `\Illuminate\Support\Arr::except(get_defined_vars(), ...)` (extends).
     */
    private function isScopeForwardingArg(Expr $expr): bool
    {
        if ($expr instanceof FuncCall && $expr->name instanceof Name) {
            return $expr->name->toLowerString() === 'array_diff_key';
        }

        if ($expr instanceof StaticCall && $expr->class instanceof FullyQualified) {
            return $expr->class->toString() === Arr::class
                && $expr->name instanceof Identifier
                && $expr->name->name === 'except';
        }

        return false;
    }
}
