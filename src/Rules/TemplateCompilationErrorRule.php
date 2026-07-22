<?php

declare(strict_types=1);

namespace Bladestan\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Surfaces template compilation failures as errors against the .blade.php file.
 *
 * When a template fails to compile (a parse error, a missing @include, a
 * throwing view composer), BladeToPHPCompiler produces an empty PHP shell and
 * records the failure as a `// @bladestan-error:` marker at the top of the
 * compiled file. That shell analyzes cleanly, so without this rule the template
 * would silently drop out of analysis. Reading the markers back turns that
 * silent false negative into a reported error.
 *
 * @implements Rule<FileNode>
 * @see \Bladestan\Tests\Rules\TemplateCompilationErrorRuleTest
 */
final class TemplateCompilationErrorRule implements Rule
{
    /**
     * Matches the `// @bladestan-error: {json}` markers emitted by
     * BladeToPHPCompiler::diagnosticHeader().
     *
     * @see https://regex101.com/r/kQ2rJ7/1
     * @var string
     */
    private const ERROR_MARKER_REGEX = '/^\/\/\s*@bladestan-error:\s*(.+)$/m';

    public function __construct(
        private readonly string $compiledViewPath,
    ) {
    }

    public function getNodeType(): string
    {
        return FileNode::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $filePath = $scope->getFile();

        // Only compiled templates carry the markers; skip every other analysed
        // file so the whole project isn't read from disk on each FileNode.
        if (! $this->isCompiledBladeFile($filePath)) {
            return [];
        }

        $contents = @file_get_contents($filePath);
        if ($contents === false) {
            return [];
        }

        if (preg_match_all(self::ERROR_MARKER_REGEX, $contents, $matches) === 0) {
            return [];
        }

        $errors = [];
        foreach ($matches[1] as $payload) {
            $decoded = json_decode(trim($payload), true);
            if (! is_array($decoded)) {
                continue;
            }

            if (! is_string($decoded['message'] ?? null)) {
                continue;
            }

            $identifier = is_string($decoded['identifier'] ?? null)
                ? $decoded['identifier']
                : 'bladestan.compilation';

            // The failure applies to the template as a whole, so it is anchored
            // to line 1; the error formatter maps this compiled file back to the
            // .blade.php path.
            $errors[] = RuleErrorBuilder::message($decoded['message'])
                ->identifier($identifier)
                ->line(1)
                ->build();
        }

        return $errors;
    }

    private function isCompiledBladeFile(string $filePath): bool
    {
        $normalizedCompiled = rtrim($this->compiledViewPath, '/\\') . DIRECTORY_SEPARATOR;
        $normalizedFile = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $filePath);

        return str_starts_with($normalizedFile, $normalizedCompiled);
    }
}
