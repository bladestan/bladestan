<?php

declare(strict_types=1);

namespace Bladestan\Rules;

use Bladestan\Compiler\SignatureMerger;
use Bladestan\Compiler\TypeStringValidator;
use Bladestan\NodeAnalyzer\BladeViewMethodsMatcher;
use Bladestan\NodeAnalyzer\LaravelViewFunctionMatcher;
use Bladestan\NodeAnalyzer\MailablesContentMatcher;
use Bladestan\NodeAnalyzer\TemplateFilePathResolver;
use Bladestan\TemplateCompiler\ValueObject\RenderTemplateWithParameters;
use InvalidArgumentException;
use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
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
    /**
     * Memoized parsed type strings — the same signature is validated at every
     * call site of a template. A null entry marks a type PHPStan's PHPDoc
     * parser rejects, so the failed parse is attempted only once.
     *
     * @var array<string, Type|null>
     */
    private array $resolvedTypeCache = [];

    public function __construct(
        private readonly BladeViewMethodsMatcher $bladeViewMethodsMatcher,
        private readonly LaravelViewFunctionMatcher $laravelViewFunctionMatcher,
        private readonly MailablesContentMatcher $mailablesContentMatcher,
        private readonly TemplateFilePathResolver $templateFilePathResolver,
        private readonly SignatureMerger $signatureMerger,
        private readonly TypeStringResolver $typeStringResolver,
        private readonly TypeStringValidator $typeStringValidator,
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
        $renderTemplatesWithParameters = match (true) {
            $node instanceof StaticCall,
            $node instanceof FuncCall => $this->laravelViewFunctionMatcher->match($node, $scope),
            $node instanceof MethodCall => $this->bladeViewMethodsMatcher->match($node, $scope),
            $node instanceof New_ => $this->mailablesContentMatcher->match($node, $scope),
            default => [],
        };

        $errors = [];
        foreach ($renderTemplatesWithParameters as $renderTemplateWithParameter) {
            $errors = array_merge($errors, $this->validateCallSite($renderTemplateWithParameter, $scope));
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

        // Only validate call sites when the template has an explicit signature
        // or an implicit first-docblock signature
        $templateSignature = $this->signatureMerger->ownSignature($bladeFilePath);
        if ($templateSignature->isEmpty()) {
            // Template has no signature — nothing to validate against
            return [];
        }

        // Get merged signature (walks @extends chain)
        $mergeErrors = [];
        $mergedSignature = $this->signatureMerger->mergeForTemplate($bladeFilePath, $mergeErrors);

        $errors = [];

        // Report merge errors (covariance violations)
        foreach ($mergeErrors as $mergeError) {
            $errors[] = RuleErrorBuilder::message($mergeError)
                ->identifier('bladestan.signatureMerge')
                ->build();
        }

        // If the merged signature is empty (all merge errors?), skip call-site validation
        if ($mergedSignature->isEmpty()) {
            return $errors;
        }

        // A single unparseable type must never abort the run. Report each one
        // as a localized error and drop it from the checks below, so the rest
        // of the signature (and every other call site) is still validated.
        $invalidTypes = [];
        foreach ($mergedSignature->variables as $varName => $expectedTypeString) {
            if ($this->resolveTypeString($expectedTypeString) instanceof Type) {
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
            $expectedTypeString = $mergedSignature->variables[$varName] ?? null;
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
            $expectedType = $this->resolveTypeString($expectedTypeString);
            assert($expectedType instanceof Type);

            // Check if the provided type is accepted by the expected type
            if (! $expectedType->isSuperTypeOf($providedType)->yes()) {
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
                    ->build();
            }
        }

        // Validate: check for missing required variables
        foreach ($mergedSignature->variables as $varName => $expectedTypeString) {
            if (isset($providedParams[$varName])) {
                continue;
            }

            if (isset($invalidTypes[$varName])) {
                continue;
            }

            // Don't report shared/framework variables as missing
            // These are automatically available in all templates at runtime
            if ($this->isSharedVariable($varName)) {
                continue;
            }

            // A scope-forwarding call site (compiled @include) passes every
            // variable in the surrounding scope to the template, so a scope
            // variable satisfies the signature — but its type still has to.
            // Certainty is required: at file level PHPStan reports unknown
            // variables as maybe-defined mixed, which must stay "missing".
            if ($renderTemplateWithParameters->forwardsScope && $scope->hasVariableType($varName)->yes()) {
                $scopeType = $scope->getVariableType($varName);
                $expectedType = $this->resolveTypeString($expectedTypeString);
                assert($expectedType instanceof Type);

                if (! $expectedType->isSuperTypeOf($scopeType)->yes()) {
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

    /**
     * Parse a PHPDoc type string into a PHPStan Type object, or null when the
     * string is not a valid PHPDoc type.
     *
     * Uses TypeStringResolver which does not require a file context, unlike
     * FileTypeMapper which cannot resolve types for .blade.php files. The
     * resolver throws on malformed types (e.g. a parenthesized union copied
     * from dumpType output); a signature author's mistake must never abort the
     * whole analysis, so the failure is contained here and surfaced as a
     * localized bladestan.invalidSignatureType error at the call site.
     */
    private function resolveTypeString(string $typeString): ?Type
    {
        if (array_key_exists($typeString, $this->resolvedTypeCache)) {
            return $this->resolvedTypeCache[$typeString];
        }

        if (! $this->typeStringValidator->isValid($typeString)) {
            return $this->resolvedTypeCache[$typeString] = null;
        }

        return $this->resolvedTypeCache[$typeString] = $this->typeStringResolver->resolve($typeString);
    }

    /**
     * Check if a variable name is a known shared/framework variable
     * that is automatically available in all templates.
     */
    private function isSharedVariable(string $varName): bool
    {
        // The 'errors' variable is always shared by Laravel (ViewErrorBag)
        /** @var list<string> $knownShared */
        $knownShared = ['errors', 'app', '__env', 'slot', 'attributes', 'component'];

        return in_array($varName, $knownShared, true);
    }
}
