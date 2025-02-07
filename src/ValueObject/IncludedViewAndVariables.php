<?php

declare(strict_types=1);

namespace Bladestan\ValueObject;

final class IncludedViewAndVariables extends AbstractInlinedElement
{
    /**
     * @var array<string>
     */
    private array $availableVariables;

    /**
     * @param array<string, string> $variablesAndValues
     */
    public function __construct(
        string $rawPhpContent,
        string $includedViewName,
        array $variablesAndValues,
        private readonly ?string $extract,
    ) {
        parent::__construct($rawPhpContent, $includedViewName, $variablesAndValues);
    }

    public function preprocessTemplate(string $includedContent): string
    {
        return $includedContent;
    }

    public function getInnerScopeVariableNames(array $availableVariables): array
    {
        // Extract variables used to create additional data
        foreach ($this->variablesAndValues as $variableAndValue) {
            preg_match_all('#\$([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)#s', $variableAndValue, $variableNames);
            $availableVariables = [...$availableVariables, ...$variableNames[1]];
        }

        if ($this->extract) {
            $availableVariables[] = substr($this->extract, 1);
        }

        $this->availableVariables = $availableVariables;

        return array_unique([...$this->availableVariables, ...array_keys($this->variablesAndValues)]);
    }

    public function generateInlineRepresentation(string $includedContent): string
    {
        $use = $this->buildUse($this->availableVariables);

        $variables = $this->variablesAndValues;
        $includedViewVariables = implode(
            PHP_EOL,
            array_map(
                static fn (string $key, string $value): string => "\${$key} = {$value};",
                array_keys($variables),
                $variables
            )
        );
        if ($this->extract) {
            $includedViewVariables .= PHP_EOL . "extract({$this->extract});";
        }

        return <<<STRING
(function () use({$use}) {
    {$includedViewVariables}
    {$includedContent}
});
STRING;
    }
}
