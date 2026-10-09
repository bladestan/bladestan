<?php

declare(strict_types=1);

namespace Bladestan\Rules;

use Bladestan\PhpParser\BladeTemplateParser;
use Bladestan\PHPStan\BladeEnvironmentValueExtension;
use PhpParser\Node;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\Rule;

/**
 * Declares what a template's compiled form depends on besides its own source,
 * so PHPStan's result cache re-analyses the template when one of those changes.
 *
 * The compiled form reflects component classes and reads application
 * configuration (directives, shared data, composers, the view finder) that
 * PHPStan cannot see. The parser that compiles the template has no scope to
 * declare them on, so it records them on the statements it hands back and this
 * rule declares them once the template is analysed. It never reports anything.
 *
 * @implements Rule<FileNode>
 * @see \Bladestan\Tests\Rules\TemplateCompilationDependencyRuleTest
 */
final class TemplateCompilationDependencyRule implements Rule
{
    public function getNodeType(): string
    {
        return FileNode::class;
    }

    /**
     * @param Scope&DependencyTracker $scope
     * @return array{}
     */
    public function processNode(Node $node, Scope $scope): array
    {
        // FileNode adopts the attributes of the file's first statement, which
        // is where the parser recorded the dependencies.
        $dependencies = $node->getAttribute(BladeTemplateParser::DEPENDENCIES_ATTRIBUTE);
        if (! is_array($dependencies)) {
            return [];
        }

        foreach ((array) ($dependencies['classes'] ?? []) as $class) {
            if (is_string($class)) {
                $scope->trackClassDependency($class);
            }
        }

        foreach ((array) ($dependencies['environment'] ?? []) as $key) {
            if (is_string($key)) {
                $scope->trackValueDependency(BladeEnvironmentValueExtension::class, $key);
            }
        }

        return [];
    }
}
