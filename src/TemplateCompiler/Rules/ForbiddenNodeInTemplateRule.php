<?php

declare(strict_types=1);

namespace Bladestan\TemplateCompiler\Rules;

use Bladestan\Enums\NodeType;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<Node>
 */
final class ForbiddenNodeInTemplateRule implements Rule
{
    private bool $forbidAllFunctionCalls = false;

    /**
     * @var array<string, true>
     */
    private array $processableNodeTypes = [];

    /**
     * @var array<string, true>
     */
    private array $forbiddenNodeTypes = [];

    /**
     * @var array<string, true>
     */
    private array $forbiddenFunctions = [];

    /**
     * @param list<array<string, mixed>> $forbiddenNodes
     */
    public function __construct(array $forbiddenNodes)
    {
        $this->configureForbiddenNodes($forbiddenNodes);
    }

    public function getNodeType(): string
    {
        return Node::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $this->isBladeCompiledTemplate($scope)) {
            return [];
        }

        $nodeType = $node->getType();
        if (! $this->shouldProcessNodeType($nodeType)) {
            return [];
        }

        if (isset($this->forbiddenNodeTypes[$nodeType])) {
            return [$this->createNodeTypeViolation($nodeType)];
        }

        if ($nodeType !== NodeType::NODE_EXPR_FUNC_CALL->value || ! $node instanceof FuncCall) {
            return [];
        }

        if (! $node->name instanceof Node\Name) {
            if (! $this->forbidAllFunctionCalls) {
                return [];
            }

            return [$this->createNodeTypeViolation(NodeType::NODE_EXPR_FUNC_CALL->value)];
        }

        $functionName = ltrim((string) $node->name, '\\');
        $normalizedFunctionName = strtolower($functionName);
        if (! $this->forbidAllFunctionCalls && ! isset($this->forbiddenFunctions[$normalizedFunctionName])) {
            return [];
        }

        return [$this->createFunctionCallViolation($functionName)];
    }

    /**
     * @param list<array<string, mixed>> $forbiddenNodes
     */
    private function configureForbiddenNodes(array $forbiddenNodes): void
    {
        foreach ($forbiddenNodes as $forbiddenNode) {
            $type = $forbiddenNode['type'] ?? null;
            if (! is_string($type)) {
                continue;
            }

            $resolvedNodeType = NodeType::tryFrom($type);
            if (! $resolvedNodeType instanceof NodeType) {
                continue;
            }

            if ($resolvedNodeType !== NodeType::NODE_EXPR_FUNC_CALL) {
                $this->forbiddenNodeTypes[$resolvedNodeType->value] = true;
                $this->processableNodeTypes[$resolvedNodeType->value] = true;
                continue;
            }

            $functions = $forbiddenNode['functions'] ?? null;
            if ($functions === null) {
                $this->enableFunctionCallProcessing();
                $this->forbidAllFunctionCalls = true;
                continue;
            }

            if (! is_array($functions)) {
                continue;
            }

            $hasValidFunction = false;
            foreach ($functions as $function) {
                if (! is_string($function)) {
                    continue;
                }

                $this->forbiddenFunctions[strtolower(ltrim($function, '\\'))] = true;
                $hasValidFunction = true;
            }

            if ($hasValidFunction) {
                $this->enableFunctionCallProcessing();
            }
        }
    }

    private function createFunctionCallViolation(string $functionName): RuleError
    {
        return RuleErrorBuilder::message(sprintf('Forbidden code: function %s() is not allowed.', $functionName))
            ->identifier('bladestan.forbiddenNode')
            ->build();
    }

    private function createNodeTypeViolation(string $nodeType): RuleError
    {
        return RuleErrorBuilder::message(sprintf('Forbidden code: %s is not allowed.', $nodeType))
            ->identifier('bladestan.forbiddenNode')
            ->build();
    }

    private function isBladeCompiledTemplate(Scope $scope): bool
    {
        return str_ends_with($scope->getFile(), '-blade-compiled.php');
    }

    private function shouldProcessNodeType(string $nodeType): bool
    {
        return isset($this->processableNodeTypes[$nodeType]);
    }

    private function enableFunctionCallProcessing(): void
    {
        $this->processableNodeTypes[NodeType::NODE_EXPR_FUNC_CALL->value] = true;
    }
}
