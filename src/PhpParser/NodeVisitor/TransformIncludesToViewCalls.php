<?php

declare(strict_types=1);

namespace Bladestan\PhpParser\NodeVisitor;

use Illuminate\Support\Arr;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
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
 * `view()` call in PHP source. `@includeIf`, `@includeWhen`, `@includeUnless`,
 * and `@includeIsolated` all compile to the same `$__env->make(...)->render()`
 * shape and are rewritten the same way.
 *
 * `@includeFirst(['a', 'b'], ...)` instead compiles to
 * `$__env->first(['a', 'b'], ...)->render()`, a list of candidate views of
 * which Blade renders the first that exists. It is validated against the last
 * candidate: that is the guaranteed fallback the others are expected to
 * satisfy, so checking it avoids false positives on the optional overrides
 * ahead of it.
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
        if (! $make instanceof MethodCall) {
            return null;
        }

        $viewArg = $this->resolveViewArg($make);
        if (! $viewArg instanceof Arg) {
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

        $args = [$viewArg, $explicitData ?? new Arg(new Array_([]))];
        if ($forwardsScope) {
            $args[] = new Arg(new FuncCall(new Name('get_defined_vars')));
        }

        $expression = new Expression(new FuncCall(new Name('view'), $args));
        $expression->setAttributes($node->getAttributes());

        return $expression;
    }

    /**
     * The view-name argument the resulting `view()` call should validate.
     *
     * `$__env->make(...)` (a compiled `@include` and its variants) names the
     * view in its first argument. `$__env->first([...], ...)` (a compiled
     * `@includeFirst`) names a list of candidates; the last is the guaranteed
     * fallback, so that one is validated. Returns null for any other call, or
     * for a dynamic view list that has no static last candidate.
     */
    private function resolveViewArg(MethodCall $methodCall): ?Arg
    {
        if (! $methodCall->var instanceof Variable
            || $methodCall->var->name !== '__env'
            || ! $methodCall->name instanceof Identifier
        ) {
            return null;
        }

        if (! isset($methodCall->args[0]) || ! $methodCall->args[0] instanceof Arg) {
            return null;
        }

        if ($methodCall->name->name === 'make') {
            return $methodCall->args[0];
        }

        if ($methodCall->name->name === 'first' && $methodCall->args[0]->value instanceof Array_) {
            $last = end($methodCall->args[0]->value->items);

            return $last instanceof ArrayItem ? new Arg($last->value) : null;
        }

        return null;
    }

    /**
     * Blade forwards the parent scope via `array_diff_key(get_defined_vars(), ...)`
     * (includes) or `\Illuminate\Support\Arr::except(get_defined_vars(), ...)` (extends).
     *
     * Both compiler-injected forms pass the surrounding scope as their first
     * argument (`get_defined_vars()`). Requiring that guards against explicit
     * user data whose runtime effect merely resembles forwarding, such as
     * `@include('x', array_diff_key($a, $b))` or `@include('x', Arr::except($a, $b))`,
     * which name their own operands and must be validated as-is.
     */
    private function isScopeForwardingArg(Expr $expr): bool
    {
        if ($expr instanceof FuncCall && $expr->name instanceof Name) {
            return $expr->name->toLowerString() === 'array_diff_key'
                && $this->forwardsDefinedVars($expr);
        }

        if ($expr instanceof StaticCall && $expr->class instanceof FullyQualified) {
            return $expr->class->toString() === Arr::class
                && $expr->name instanceof Identifier
                && $expr->name->name === 'except'
                && $this->forwardsDefinedVars($expr);
        }

        return false;
    }

    /**
     * True when the call's first argument is a bare `get_defined_vars()`, the
     * shape the Blade compiler emits when forwarding the surrounding scope.
     */
    private function forwardsDefinedVars(FuncCall|StaticCall $call): bool
    {
        $first = $call->args[0] ?? null;
        if (! $first instanceof Arg) {
            return false;
        }

        return $first->value instanceof FuncCall
            && $first->value->name instanceof Name
            && $first->value->name->toLowerString() === 'get_defined_vars';
    }
}
