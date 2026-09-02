<?php

declare(strict_types=1);

namespace Bladestan\Rules;

use Bladestan\Compiler\SignatureMerger;
use Bladestan\Compiler\TypeStringValidator;
use Bladestan\NodeAnalyzer\BladeScopeVariables;
use Bladestan\NodeAnalyzer\RenderSiteMatcher;
use Bladestan\NodeAnalyzer\TemplateFilePathResolver;
use Bladestan\ValueObject\RenderTemplateWithParameters;
use InvalidArgumentException;
use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Rules\RuleLevelHelper;
use PHPStan\Type\Type;
use PHPStan\Type\VerbosityLevel;
use ValueError;

/**
 * Validates view() calls against merged template signatures.
 *
 * This is a standard PHPStan Rule — no collector, no pre-scan, no multi-pass.
 * It reads the template's @bladestan-signature, walks @extends chains,
 * merges signatures, and validates that the call site provides the correct
 * parameters.
 *
 * @implements Rule<CallLike>
 * @see \Bladestan\Tests\Rules\ViewCallSiteRuleTest
 */
final class ViewCallSiteRule implements Rule
{
    public function __construct(
        private readonly RenderSiteMatcher $renderSiteMatcher,
        private readonly TemplateFilePathResolver $templateFilePathResolver,
        private readonly SignatureMerger $signatureMerger,
        private readonly TypeStringValidator $typeStringValidator,
        private readonly RuleLevelHelper $ruleLevelHelper,
    ) {
    }

    public function getNodeType(): string
    {
        return CallLike::class;
    }

    /**
     * @return list<IdentifierRuleError>
     * @throws ValueError
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $errors = [];
        foreach ($this->renderSiteMatcher->match($node, $scope) as $renderTemplateWithParameter) {
            $errors = [...$errors, ...$this->validateCallSite($renderTemplateWithParameter, $scope)];
        }

        return $errors;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function validateCallSite(
        RenderTemplateWithParameters $renderTemplateWithParameters,
        Scope $scope
    ): array {
        // Resolve view name → file path
        try {
            $bladeFilePath = $this->templateFilePathResolver->resolveExistingFilePath(
                $renderTemplateWithParameters->templateName,
            );
        } catch (InvalidArgumentException $invalidArgumentException) {
            return [
                RuleErrorBuilder::message($invalidArgumentException->getMessage())
                    ->identifier('bladestan.templateNotFound')
                    ->build(),
            ];
        }

        // Validate against the merged signature (the template's own, combined
        // with its @extends chain). A child that declares nothing of its own
        // still inherits its layout's contract: Blade forwards the child's
        // whole scope to the layout, so a call site missing a layout-required
        // variable is just as broken as one missing the child's own.
        $mergedSignature = $this->signatureMerger->mergeForTemplate($bladeFilePath);
        $templateSignature = $mergedSignature->signature;

        $errors = [];

        // Covariance violations and unresolvable @extends parents are defects of
        // the template itself, not of this call site, so TemplateSignatureMergeRule
        // reports them once against the template rather than at every call site.

        // If the merged signature is empty, there is nothing to validate against.
        if ($templateSignature->isEmpty()) {
            return $errors;
        }

        // A single unparsable type must never abort the run. Report each one
        // as a localized error and drop it from the checks below, so the rest
        // of the signature (and every other call site) is still validated.
        $invalidTypes = [];
        foreach ($templateSignature->variables as $varName => $expectedTypeString) {
            if ($this->typeStringValidator->resolve($expectedTypeString) instanceof Type) {
                continue;
            }

            $invalidTypes[$varName] = true;
            $errors[] = RuleErrorBuilder::message(
                sprintf(
                    'Template %s declares $%s as %s, which is not a valid PHPDoc type.',
                    $renderTemplateWithParameters->templateName,
                    $varName,
                    $expectedTypeString,
                ),
            )
                ->identifier('bladestan.invalidSignatureType')
                ->build();
        }

        $providedParams = $renderTemplateWithParameters->parametersArray;

        // Validate: check types of provided variables
        foreach ($providedParams as $varName => $providedType) {
            $expectedTypeString = $templateSignature->variables[$varName] ?? null;
            if ($expectedTypeString === null) {
                // Variable provided but not in signature — not an error,
                // extra variables are allowed (they're just unused)
                continue;
            }

            if (isset($invalidTypes[$varName])) {
                // Already reported as an invalid type — don't check against it.
                continue;
            }

            // Parse the expected type string into a PHPStan Type
            $expectedType = $this->typeStringValidator->resolve($expectedTypeString);
            assert($expectedType instanceof Type);

            // Check acceptance the same way PHPStan checks a function argument:
            // RuleLevelHelper honours the analysis level (checkExplicitMixed,
            // checkNullables, checkUnionTypes), so a mixed value passed for a
            // typed variable is only reported where PHPStan would report the
            // equivalent function call. Being stricter than the configured level
            // would surface errors the rest of the analysis never emits.
            $accepts = $this->ruleLevelHelper->accepts($expectedType, $providedType, $scope->isDeclareStrictTypes());
            if (! $accepts->result) {
                $errors[] = RuleErrorBuilder::message(
                    sprintf(
                        'Template %s expects parameter $%s of type %s, but %s given.',
                        $renderTemplateWithParameters->templateName,
                        $varName,
                        $expectedTypeString,
                        $providedType->describe(VerbosityLevel::typeOnly()),
                    ),
                )
                    ->identifier('bladestan.parameterType')
                    ->acceptsReasonsTip($accepts->reasons)
                    ->build();
            }
        }

        // Validate: check for missing required variables. Skipped entirely
        // when the data argument's shape couldn't be resolved (e.g.
        // array_merge(), a typed variable): an opaque value may supply any
        // variable the signature requires, so "not found in $providedParams"
        // no longer means "not provided".
        foreach ($templateSignature->variables as $varName => $expectedTypeString) {
            if ($renderTemplateWithParameters->hasUnresolvedData) {
                continue;
            }

            if (isset($providedParams[$varName])) {
                continue;
            }

            if (isset($invalidTypes[$varName])) {
                continue;
            }

            // Don't report the variables Blade supplies itself (the error bag,
            // the environment, component and loop internals) as missing: they
            // are available in every template at runtime, not part of its
            // contract.
            if (BladeScopeVariables::isInternal($varName)) {
                continue;
            }

            // A scope-forwarding call site (compiled @include) passes every
            // variable in the surrounding scope to the template, so a scope
            // variable satisfies the signature — but its type still has to.
            // Certainty is required: at file level PHPStan reports unknown
            // variables as maybe-defined mixed, which must stay "missing".
            if ($renderTemplateWithParameters->forwardsScope && $scope->hasVariableType($varName)->yes()) {
                $scopeType = $scope->getVariableType($varName);
                $expectedType = $this->typeStringValidator->resolve($expectedTypeString);
                assert($expectedType instanceof Type);

                $accepts = $this->ruleLevelHelper->accepts($expectedType, $scopeType, $scope->isDeclareStrictTypes());
                if (! $accepts->result) {
                    $errors[] = RuleErrorBuilder::message(
                        sprintf(
                            'Template %s expects parameter $%s of type %s, but %s given by the surrounding scope.',
                            $renderTemplateWithParameters->templateName,
                            $varName,
                            $expectedTypeString,
                            $scopeType->describe(VerbosityLevel::typeOnly()),
                        ),
                    )
                        ->identifier('bladestan.parameterType')
                        ->acceptsReasonsTip($accepts->reasons)
                        ->build();
                }

                continue;
            }

            $errors[] = RuleErrorBuilder::message(
                sprintf(
                    'Template %s requires parameter $%s of type %s, but it was not provided.',
                    $renderTemplateWithParameters->templateName,
                    $varName,
                    $expectedTypeString,
                ),
            )
                ->identifier('bladestan.missingParameter')
                ->build();
        }

        return $errors;
    }
}
