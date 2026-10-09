<?php

declare(strict_types=1);

namespace Bladestan\Rules;

use Bladestan\PhpParser\BladeTemplateParser;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Surfaces template compilation failures as errors against the template.
 *
 * When a template fails to compile (a parse error, a missing @include, a
 * throwing view composer) it has no statements to analyse, so without this rule
 * it would drop out of analysis silently and read as clean. BladeTemplateParser
 * records each failure on the statements it hands back; reading them here turns
 * that silent false negative into a reported error.
 *
 * @implements Rule<FileNode>
 * @see \Bladestan\Tests\Rules\TemplateCompilationErrorRuleTest
 */
final class TemplateCompilationErrorRule implements Rule
{
    public function getNodeType(): string
    {
        return FileNode::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        // FileNode adopts the attributes of the file's first statement, which
        // is where the parser recorded the failures.
        $failures = $node->getAttribute(BladeTemplateParser::COMPILATION_ERRORS_ATTRIBUTE);
        if (! is_array($failures)) {
            return [];
        }

        $errors = [];
        foreach ($failures as $failure) {
            if (! is_array($failure) || ! is_string($failure['message'] ?? null)) {
                continue;
            }

            $identifier = is_string($failure['identifier'] ?? null)
                ? $failure['identifier']
                : 'bladestan.compilation';

            // The failure applies to the template as a whole, so it is anchored
            // to its first line rather than to any statement inside it.
            $errors[] = RuleErrorBuilder::message($failure['message'])
                ->identifier($identifier)
                ->line(1)
                ->build();
        }

        return $errors;
    }
}
