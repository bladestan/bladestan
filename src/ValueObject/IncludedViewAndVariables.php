<?php

declare(strict_types=1);

namespace Bladestan\ValueObject;

final class IncludedViewAndVariables extends AbstractInlinedElement
{
    /**
     * @var array<string>
     */
    private array $avalibleVariables;

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

    public function getInnerScopeVariableNames(array $avalibleVariables): array
    {
        // Extract variables used to create additional data
        foreach ($this->variablesAndValues as $variableAndValue) {
            preg_match_all('#\$([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)#s', $variableAndValue, $variableNames);
            $avalibleVariables = [...$avalibleVariables, ...$variableNames[1]];
        }

        if ($this->extract) {
            $avalibleVariables[] = substr($this->extract, 1);
        }

        $this->avalibleVariables = $avalibleVariables;

        return array_unique([...$this->avalibleVariables, ...array_keys($this->variablesAndValues)]);
    }

    public function generateInlineRepresentation(string $includedContent): string
    {
        $use = $this->buildUse($this->avalibleVariables);

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
