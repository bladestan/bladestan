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
 * particular call site that renders it. Reporting it here, once per template
 * file, surfaces each violation a single time. ViewCallSiteRule used to emit
 * these at every call site, so one widened type produced a wall of duplicates
 * in busy projects.
 *
 * @implements Rule<FileNode>
 * @see \Bladestan\Tests\Rules\TemplateSignatureMergeRuleTest
 */
final class TemplateSignatureMergeRule implements Rule
{
    private const TEMPLATE_SUFFIX = '.blade.php';

    public function __construct(
        private readonly SignatureMerger $signatureMerger,
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
        if (! str_ends_with($filePath, self::TEMPLATE_SUFFIX)) {
            return [];
        }

        $errors = [];
        foreach ($this->signatureMerger->mergeForTemplate($filePath)->errors as $mergeError) {
            // The violation applies to the template as a whole, so it is
            // anchored to its first line rather than to any statement inside it.
            $errors[] = RuleErrorBuilder::message($mergeError)
                ->identifier('bladestan.signatureMerge')
                ->line(1)
                ->build();
        }

        return $errors;
    }
}
