<?php

declare(strict_types=1);

namespace Bladestan\ValueObject;

final class IncludedViewAndVariables extends AbstractInlinedElement
{
    /**
     * @var array<string>
     */
    private array $avalibleVariables;

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

        return <<<STRING
(function (){$use} {
    {$includedViewVariables}
    {$includedContent}
});
STRING;
    }
}
