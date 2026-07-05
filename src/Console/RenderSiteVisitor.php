<?php

declare(strict_types=1);

namespace Bladestan\Console;

use Bladestan\Console\ValueObject\RenderSite;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;

/**
 * Collects render sites while traversing a PHP file's AST.
 *
 * A render site is a `view()`, `View::make()`, `->view()` or `->markdown()`
 * call whose first argument is a string template name and which passes a second
 * (data) argument. The enclosing statement's start offset is tracked so the
 * generator can inject a `\PHPStan\dumpType()` of the data just before it.
 */
final class RenderSiteVisitor extends NodeVisitorAbstract
{
    /**
     * @var list<RenderSite>
     */
    private array $renderSites = [];

    /**
     * Start offsets of the currently open statements, innermost last.
     *
     * @var list<int>
     */
    private array $statementStartStack = [];

    public function __construct(
        private readonly string $filePath,
    ) {
    }

    public function enterNode(Node $node): null
    {
        if ($node instanceof Stmt) {
            $this->statementStartStack[] = $node->getStartFilePos();
        }

        if (! $this->isRenderCall($node)) {
            return null;
        }

        /** @var FuncCall|MethodCall|StaticCall $node */
        $args = $node->getArgs();
        if (count($args) < 2) {
            return null;
        }

        $templateName = $args[0]->value;
        if (! $templateName instanceof String_) {
            return null;
        }

        $data = $args[1]->value;
        $statementStart = $this->statementStartStack === []
            ? $node->getStartFilePos()
            : $this->statementStartStack[count($this->statementStartStack) - 1];

        $this->renderSites[] = new RenderSite(
            $this->filePath,
            $templateName->value,
            $data->getStartFilePos(),
            $data->getEndFilePos(),
            $statementStart,
        );

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if ($node instanceof Stmt) {
            array_pop($this->statementStartStack);
        }

        return null;
    }

    /**
     * @return list<RenderSite>
     */
    public function getRenderSites(): array
    {
        return $this->renderSites;
    }

    private function isRenderCall(Node $node): bool
    {
        if ($node instanceof FuncCall) {
            return $node->name instanceof Name && $node->name->toString() === 'view';
        }

        if ($node instanceof StaticCall) {
            return $node->class instanceof Name
                && $node->class->getLast() === 'View'
                && $node->name instanceof Identifier
                && $node->name->toString() === 'make';
        }

        if ($node instanceof MethodCall) {
            return $node->name instanceof Identifier
                && in_array($node->name->toString(), ['view', 'markdown'], true);
        }

        return false;
    }
}
