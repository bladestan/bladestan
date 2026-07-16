<?php

declare(strict_types=1);

namespace Bladestan\Console\Extraction;

use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use function array_key_exists;
use function file_get_contents;
use function is_string;
use function preg_match;
use function trim;

/**
 * Records the variables a compiled template reads without defining them itself.
 *
 * A partial reached through a bare `@include` declares no data of its own: the
 * variables it uses are forwarded from the including template's scope. To write
 * that partial a signature, the generator first needs to know *which* variables
 * it depends on. This collector answers that by reporting, per source template,
 * every variable the template reads without defining, i.e. exactly the reads
 * PHPStan would flag as (possibly) undefined. {@see ViewSignatureCollectedDataRule}
 * then types each from what the including templates forward (harvested by
 * {@see ViewDataCollector}).
 *
 * The read/write and defined checks mirror PHPStan's own `DefinedVariableRule`,
 * so a loop or assignment target is not mistaken for an input, and a variable
 * read once under a guard (`@if($x)`) still counts even where a later read sees
 * it as defined.
 *
 * Only compiled templates are considered; a file without the
 * `// @bladestan-source:` header a compiled template carries is ignored, so
 * ordinary project PHP is never mistaken for a template.
 *
 * @implements Collector<Variable, array{source: string, name: string}>
 * @see \Bladestan\Tests\Console\Extraction\ViewSignatureCollectedDataRuleTest
 */
final class TemplateFreeVariableCollector implements Collector
{
    /**
     * Compiled file path => the source `.blade.php` path it was compiled from,
     * or null when the file is not a compiled template. Reading the header once
     * per file keeps this cheap across the many variable nodes in a template.
     *
     * @var array<string, string|null>
     */
    private static array $sourceByCompiledFile = [];

    public function getNodeType(): string
    {
        return Variable::class;
    }

    /**
     * @param Variable $node
     * @return array{source: string, name: string}|null
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        if (! is_string($node->name) || BladeScopeVariables::isInternal($node->name)) {
            return null;
        }

        // Mirror PHPStan's DefinedVariableRule: a write target defines rather
        // than reads, and a read allowed to be undefined (isset(), ?? , ...) is
        // not a required input. What is left is a read of a variable not defined
        // here, i.e. an input the template expects from its includer.
        if ($scope->isInExpressionAssign($node) || $scope->isUndefinedExpressionAllowed($node)) {
            return null;
        }

        if ($scope->hasVariableType($node->name)->yes()) {
            return null;
        }

        $source = $this->resolveSourceTemplate($scope->getFile());
        if ($source === null) {
            return null;
        }

        return [
            'source' => $source,
            'name' => $node->name,
        ];
    }

    private function resolveSourceTemplate(string $compiledFile): ?string
    {
        if (array_key_exists($compiledFile, self::$sourceByCompiledFile)) {
            return self::$sourceByCompiledFile[$compiledFile];
        }

        $source = null;
        $contents = @file_get_contents($compiledFile);
        if ($contents !== false && preg_match('/^\/\/\s*@bladestan-source:\s*(.+)$/m', $contents, $matches) === 1) {
            $source = trim($matches[1]);
        }

        return self::$sourceByCompiledFile[$compiledFile] = $source;
    }
}
