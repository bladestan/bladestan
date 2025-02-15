<?php

declare(strict_types=1);

namespace Bladestan\ValueObject;

abstract class AbstractInlinedElement
{
    /**
     * @param array<string, string> $variablesAndValues
     */
    public function __construct(
        public readonly string $rawPhpContent,
        public readonly string $includedViewName,
        protected readonly array $variablesAndValues,
    ) {
    }

    /**
     * @param list<string> $sharedVars
     */
    abstract public function preprocessTemplate(string $includedContent, array $sharedVars): string;

    /**
     * @param array<string> $availableVariables
     *
     * @return array<string>
     */
    abstract public function getInnerScopeVariableNames(array $availableVariables): array;

    abstract public function generateInlineRepresentation(string $includedContent): string;

    /**
     * @param array<string> $variables
     */
    protected function buildUse(array $variables): string
    {
        $variables = array_filter($variables, fn ($name): bool => $name !== 'this');
        if ($variables === []) {
            return '';
        }

        $variables = implode(', ', array_map(static fn (string $variable): string => "\${$variable}", $variables));

        return " use({$variables})";
    }
}
