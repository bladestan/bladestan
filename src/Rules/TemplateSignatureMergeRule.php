<?php

declare(strict_types=1);

namespace Bladestan\Rules;

use Bladestan\Compiler\SignatureMerger;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Reports `@extends` signature-merge violations once, against the template.
 *
 * A covariance violation (a child widening a parent's declared type), or an
 * unresolvable extends parent, is a defect of the template itself, not of any
 * particular call site that renders it. Reporting it here, once per
 * compiled template file, surfaces each violation a single time and anchors it
 * to line 1 of the compiled file, which the error formatter maps back to the
 * .blade.php path. ViewCallSiteRule used to emit these at every call site, so
 * one widened type produced a wall of duplicates in busy projects.
 *
 * @implements Rule<FileNode>
 * @see \Bladestan\Tests\Rules\TemplateSignatureMergeRuleTest
 */
final class TemplateSignatureMergeRule implements Rule
{
    /**
     * Matches the `// @bladestan-source: /path/to/file.blade.php` header that
     * BladeToPHPCompiler writes at the top of every compiled template.
     *
     * @var string
     */
    private const SOURCE_HEADER_REGEX = '/^\/\/\s*@bladestan-source:\s*(.+)$/m';

    public function __construct(
        private readonly SignatureMerger $signatureMerger,
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

        // Only compiled templates carry the source header; skip every other
        // analysed file so the whole project isn't read from disk on each FileNode.
        if (! $this->isCompiledBladeFile($filePath)) {
            return [];
        }

        $contents = @file_get_contents($filePath);
        if ($contents === false) {
            return [];
        }

        if (preg_match(self::SOURCE_HEADER_REGEX, $contents, $matches) !== 1) {
            return [];
        }

        $bladeFilePath = trim($matches[1]);

        $errors = [];
        foreach ($this->signatureMerger->mergeForTemplate($bladeFilePath)->errors as $mergeError) {
            // The violation applies to the template as a whole, so it is
            // anchored to line 1; the error formatter maps this compiled file
            // back to the .blade.php path.
            $errors[] = RuleErrorBuilder::message($mergeError)
                ->identifier('bladestan.signatureMerge')
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
