<?php

declare(strict_types=1);

namespace Bladestan\PhpParser\NodeVisitor;

use Illuminate\Support\Arr;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
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
 *     view('partials.header', ['title' => $title], get_defined_vars());
 *
 * The resulting `view()` call is validated by `ViewCallSiteRule` against the
 * included template's signature — `@include` is a call site, exactly like a
 * `view()` call in PHP source.
 *
 * Blade's implicit scope forwarding (`array_diff_key(get_defined_vars(), ...)`
 * for includes, `Arr::except(get_defined_vars(), ...)` for extends) is
 * normalized to a bare `get_defined_vars()` in `view()`'s third parameter
 * (`$mergeData`), which has the same runtime meaning. `ViewCallSiteRule`
 * recognises it and lets variables from the surrounding scope satisfy the
 * partial's signature, exactly as they do at runtime. Compiled calls without
 * a forwarding argument (`@each`) stay without one, so only explicit data
 * counts there.
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

        $explicitData = null;
        $forwardsScope = false;
        foreach (array_slice($make->args, 1) as $arg) {
            if (! $arg instanceof Arg) {
                continue;
            }

            if ($this->isScopeForwardingArg($arg->value)) {
                $forwardsScope = true;
            } elseif (! $explicitData instanceof Arg) {
                $explicitData = $arg;
            }
        }

        $args = [$make->args[0], $explicitData ?? new Arg(new Array_([]))];
        if ($forwardsScope) {
            $args[] = new Arg(new FuncCall(new Name('get_defined_vars')));
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
