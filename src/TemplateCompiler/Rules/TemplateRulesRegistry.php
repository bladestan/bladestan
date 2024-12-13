<?php

declare(strict_types=1);

namespace TomasVotruba\Bladestan\TemplateCompiler\Rules;

use PhpParser\Node;
use PHPStan\Rules\Registry;
use PHPStan\Rules\Rule;

/**
 * @phpstan-ignore phpstanApi.interface
 */
final class TemplateRulesRegistry implements Registry
{
    /**
     * @var string[]
     */
    private const EXCLUDED_RULES = [
        'Symplify\PHPStanRules\Rules\ForbiddenFuncCallRule',
        'Symplify\PHPStanRules\Rules\NoDynamicNameRule',
    ];

    /**
     * @var Rule[][]
     * @phpstan-ignore missingType.generics
     */
    private array $rules = [];

    /**
     * @var Rule[][]
     * @phpstan-ignore missingType.generics
     */
    private array $cache = [];

    /**
     * @param array<Rule<Node>> $rules
     */
    public function __construct(array $rules)
    {
        $rules = $this->filterActiveRules($rules);
        foreach ($rules as $rule) {
            $this->rules[$rule->getNodeType()][] = $rule;
        }
    }

    /**
     * @template TNodeType of Node
     * @param class-string<TNodeType> $nodeType
     * @return array<Rule<TNodeType>>
     */
    public function getRules(string $nodeType): array
    {
        if (! isset($this->cache[$nodeType])) {
            /** @phpstan-ignore phpstanApi.runtimeReflection */
            $parentNodeTypes = [$nodeType] + class_parents($nodeType);

            /** @phpstan-ignore phpstanApi.runtimeReflection */
            $parentNodeTypes += class_implements($nodeType);

            $rules = [];
            foreach ($parentNodeTypes as $parentNodeType) {
                foreach ($this->rules[$parentNodeType] ?? [] as $rule) {
                    $rules[] = $rule;
                }
            }

            $this->cache[$nodeType] = $rules;
        }

        /** @var array<Rule<TNodeType>> $selectedRules */
        $selectedRules = $this->cache[$nodeType];

        return $selectedRules;
    }

    /**
     * @param array<Rule<Node>> $rules
     * @return array<Rule<Node>>
     */
    private function filterActiveRules(array $rules): array
    {
        $activeRules = [];

        foreach ($rules as $rule) {
            foreach (self::EXCLUDED_RULES as $excludedRule) {
                if ($rule instanceof $excludedRule) {
                    continue 2;
                }
            }

            $activeRules[] = $rule;
        }

        return $activeRules;
    }
}
