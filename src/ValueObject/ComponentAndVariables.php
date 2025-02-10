<?php

declare(strict_types=1);

namespace Bladestan\ValueObject;

use Bladestan\PhpParser\ArrayStringToArrayConverter;
use Illuminate\View\ComponentAttributeBag;
use Illuminate\View\ComponentSlot;

final class ComponentAndVariables extends AbstractInlinedElement
{
    /**
     * @var array<string, string>
     */
    private array $defaults;

    /**
     * @var array<string>
     */
    private array $innerUse;

    /**
     * @param array<string, string> $variablesAndValues
     */
    public function __construct(
        string $rawPhpContent,
        string $includedViewName,
        array $variablesAndValues,
        private readonly ArrayStringToArrayConverter $arrayStringToArrayConverter,
    ) {
        $variablesAndValues += [
            'slot' => 'new \\' . ComponentSlot::class . '()',
            'attributes' => 'new \\' . ComponentAttributeBag::class . '()',
            'componentName' => "''",
        ];

        parent::__construct($rawPhpContent, $includedViewName, $variablesAndValues);
    }

    public function preprocessTemplate(string $includedContent): string
    {
        if (preg_match('/@props\((\[.*?\])\)/s', $includedContent, $match) === 1) {
            $directive = $match[0];
            $props = $match[1];

            // Expand match until all nested parenthecies are found
            if (! $this->hasEvenNumberOfParentheses($directive)) {
                $startPos = strpos($includedContent, $directive);
                assert($startPos !== false);
                $length = strlen($directive);
                $maxLength = strlen($includedContent) - $startPos;
                while (! $this->hasEvenNumberOfParentheses($directive) && $length < $maxLength) {
                    $length++;
                    $directive = substr($includedContent, $startPos, $length - $startPos);
                }

                if (! $this->hasEvenNumberOfParentheses($directive)) {
                    $this->defaults = [];
                    $this->innerUse = array_keys($this->variablesAndValues);
                    return $includedContent;
                }

                $props = substr($directive, 7, -1);
            }

            $includedContent = str_replace($directive, '', $includedContent);
            $props = $this->arrayStringToArrayConverter->convert($props);
            $this->defaults = array_filter(
                $props,
                fn (string $value, int|string $key): bool => is_string($key),
                ARRAY_FILTER_USE_BOTH
            );
            $allowed = array_filter(
                $props,
                fn (string $value, int|string $key): bool => is_int($key),
                ARRAY_FILTER_USE_BOTH
            );
            $this->innerUse = [...array_keys($this->defaults), ...array_map(
                fn (string $value): string => substr($value, 1, -1),
                $allowed
            ), 'slot', 'attributes'];
        } else {
            $this->defaults = [];
            $this->innerUse = array_keys($this->variablesAndValues);
        }

        return $includedContent;
    }

    public function getInnerScopeVariableNames(array $availableVariables): array
    {
        return array_unique(['__env', ...$this->innerUse]);
    }

    public function generateInlineRepresentation(string $includedContent): string
    {
        $includeVariables = [];
        // Extract outer variables used to create inner variables
        foreach ($this->variablesAndValues as $variableAndValue) {
            preg_match_all('#\$([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)#s', $variableAndValue, $variableNames);
            $includeVariables = [...$includeVariables, ...$variableNames[1]];
        }

        $outerUse = $this->buildUse(['__env', ...$includeVariables]);
        $innerUse = $this->buildUse(['__env', ...$this->innerUse]);

        $variables = $this->variablesAndValues;
        foreach ($this->defaults as $key => $default) {
            if (! isset($variables[$key])) {
                $variables[$key] = $default;
            }
        }

        $includedViewVariables = implode(
            PHP_EOL,
            array_map(
                static fn (string $key, string $value): string => "\${$key} = {$value};",
                array_keys($variables),
                $variables
            )
        );

        return <<<STRING
(function () use({$outerUse}) {
    {$includedViewVariables}
    (function () use({$innerUse}) {
        {$includedContent}
    });
});
STRING;
    }

    private function hasEvenNumberOfParentheses(string $expression): bool
    {
        return substr_count($expression, '(') === substr_count($expression, ')');
    }
}
