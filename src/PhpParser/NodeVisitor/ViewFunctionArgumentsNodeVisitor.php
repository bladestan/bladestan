<?php

declare(strict_types=1);

namespace Bladestan\PhpParser\NodeVisitor;

use Illuminate\Support\Str;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeVisitorAbstract;

/**
 * @api part of phpstan node visitors
 */
final class ViewFunctionArgumentsNodeVisitor extends NodeVisitorAbstract
{
    public function enterNode(Node $node): ?Node
    {
        if ($node instanceof MethodCall
            && $node->var instanceof CallLike
            && $node->name instanceof Identifier
            && str_starts_with($node->name->name, 'with')
            && (count($node->args) === 1 || count($node->args) === 2)
        ) {
            $root = $node->var;
            while (true) {
                if ($root instanceof StaticCall || $root instanceof New_ || $root instanceof FuncCall) {
                    break; // Found root
                }

                if (! $root instanceof MethodCall) {
                    return null; // We only support chains
                }

                if (! $root->name instanceof Identifier) {
                    return null;
                }

                if (! str_starts_with($root->name->name, 'with')) {
                    break; // Found root
                }

                $root = $root->var;
            }

            $vars = $this->extractVariables($node->name->name, $node->getArgs());
            if ($vars === []) {
                return null;
            }

            /** @var array<string, Expr> */
            $existing = $root->getAttribute('viewWithArgs', []);
            $root->setAttribute('viewWithArgs', $vars + $existing);
        }

        return null;
    }

    /**
     * @param array<Arg> $args
     *
     * @return array<string, Expr>
     */
    private function extractVariables(string $name, array $args): array
    {
        $values = [];
        if ($name === 'with') {
            if (count($args) === 2 && $args[0]->value instanceof String_) {
                // ->with('key', $var)
                $values[$args[0]->value->value] = $args[1]->value;
            } elseif (count($args) === 1 && $args[0]->value instanceof Array_) {
                // ->with(['key' => $var])
                foreach ($args[0]->value->items as $element) {
                    if (! $element->key instanceof String_) {
                        continue;
                    }

                    $values[$element->key->value] = $element->value;
                }
            }
        } elseif (count($args) === 1) {
            // ->withKey($var)
            $values[Str::camel(substr($name, 4))] = $args[0]->value;
        }

        return $values;
    }
}
