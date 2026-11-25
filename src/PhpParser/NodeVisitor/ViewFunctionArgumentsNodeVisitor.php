<?php

declare(strict_types=1);

namespace Bladestan\PhpParser\NodeVisitor;

use Illuminate\Support\Str;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeVisitorAbstract;

/**
 * Collects the data passed through `->with(...)` magic calls and records it on
 * the root view-producing call, where the matchers read it back via
 * {@see \Bladestan\NodeAnalyzer\MagicViewWithCallParameterResolver}.
 *
 * Two attributes are written on the root:
 * - `viewWithArgs`: the variables whose value expressions could be resolved.
 * - `viewWithUnresolved`: set when a `->with()` passes data whose shape can't
 *   be read statically (`->with(compact(...))`, `->with($array)`, a dynamic
 *   key). The matcher turns this into the same unresolved-data signal a
 *   non-literal second argument produces, so the missing-parameter check is
 *   skipped rather than reporting every signature variable as missing.
 *
 * @api part of phpstan node visitors
 */
final class ViewFunctionArgumentsNodeVisitor extends NodeVisitorAbstract
{
    /**
     * Variables currently holding a view-producing call, so a `->with()` in a
     * later statement (`$view = view('x'); $view->with('title', $t);`) can be
     * attached back to the originating call. Reset per file.
     *
     * @var array<string, CallLike>
     */
    private array $viewRoots = [];

    /**
     * @param Node[] $nodes
     */
    public function beforeTraverse(array $nodes): ?array
    {
        $this->viewRoots = [];

        return null;
    }

    public function enterNode(Node $node): ?Node
    {
        // Track `$view = view(...)` (or a fluent chain) so a `->with()` on the
        // variable in a later statement resolves back to the view() call.
        if ($node instanceof Assign
            && $node->var instanceof Variable
            && is_string($node->var->name)
        ) {
            $root = $this->findChainRoot($node->expr);
            if ($root instanceof CallLike) {
                $this->viewRoots[$node->var->name] = $root;
            } else {
                // Reassigned to something that isn't a view-producing call —
                // forget any view this variable used to hold so a later
                // ->with() isn't misattributed to it.
                unset($this->viewRoots[$node->var->name]);
            }

            return null;
        }

        if (! $node instanceof MethodCall
            || ! $node->name instanceof Identifier
            || ! str_starts_with($node->name->name, 'with')
            || (count($node->args) !== 1 && count($node->args) !== 2)
        ) {
            return null;
        }

        $root = $this->findChainRoot($node->var);
        if (! $root instanceof CallLike) {
            return null;
        }

        [$vars, $complete] = $this->extractVariables($node->name->name, $node->getArgs());

        if (! $complete) {
            $root->setAttribute('viewWithUnresolved', true);
        }

        if ($vars !== []) {
            /** @var array<string, Expr> $existing */
            $existing = $root->getAttribute('viewWithArgs', []);
            $root->setAttribute('viewWithArgs', $vars + $existing);
        }

        return null;
    }

    /**
     * Walk up a `->with()->with()` chain to the call that produced the view,
     * following a variable back to its assigned view() call when the chain is
     * rooted in one (`$view = view('x'); $view->with(...)`).
     */
    private function findChainRoot(Expr $expr): ?CallLike
    {
        $root = $expr;
        while (true) {
            if ($root instanceof Variable && is_string($root->name)) {
                return $this->viewRoots[$root->name] ?? null;
            }

            if ($root instanceof StaticCall || $root instanceof New_ || $root instanceof FuncCall) {
                return $root;
            }

            if (! $root instanceof MethodCall) {
                return null;
            }

            if (! $root->name instanceof Identifier) {
                return null;
            }

            if (! str_starts_with($root->name->name, 'with')) {
                // A non-with method call (e.g. $factory->make('x')) is itself
                // the view-producing root.
                return $root;
            }

            $root = $root->var;
        }
    }

    /**
     * Extract the variables a single `->with()` call passes.
     *
     * @param array<Arg> $args
     *
     * @return array{0: array<string, Expr>, 1: bool} the resolved variables and
     *     whether every value they carry was statically visible; a false second
     *     element means part (or all) of the passed data is opaque
     */
    private function extractVariables(string $name, array $args): array
    {
        if ($name === 'with') {
            if (count($args) === 2) {
                // ->with('key', $var); a dynamic key hides which variable is set.
                if (! $args[0]->value instanceof String_) {
                    return [[], false];
                }

                return [[
                    $args[0]->value->value => $args[1]->value,
                ], true];
            }

            // count($args) === 1
            if (! $args[0]->value instanceof Array_) {
                // ->with(compact(...)), ->with($arrayVar), ... — shape unknown.
                return [[], false];
            }

            // ->with(['key' => $var])
            $values = [];
            $complete = true;
            foreach ($args[0]->value->items as $element) {
                if (! $element->key instanceof String_) {
                    // A dynamic key or a spread (`...$more`) hides part of the data.
                    $complete = false;
                    continue;
                }

                $values[$element->key->value] = $element->value;
            }

            return [$values, $complete];
        }

        // ->withKey($var) magic setter
        if (count($args) === 1) {
            return [[
                Str::camel(substr($name, 4)) => $args[0]->value,
            ], true];
        }

        // ->withSomething($a, $b) — not a recognised magic-with form.
        return [[], false];
    }
}
