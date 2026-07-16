<?php

declare(strict_types=1);

namespace Bladestan\Console\Extraction;

use Bladestan\Compiler\TypeStringValidator;
use Bladestan\NodeAnalyzer\BladeViewMethodsMatcher;
use Bladestan\NodeAnalyzer\LaravelViewFunctionMatcher;
use Bladestan\NodeAnalyzer\MailablesContentMatcher;
use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Type\GeneralizePrecision;
use PHPStan\Type\Type;
use PHPStan\Type\VerbosityLevel;
use ValueError;

/**
 * Harvests the variable types PHPStan infers at each render site, so signatures
 * can reflect what controllers actually pass rather than a hand-guessed
 * contract.
 *
 * This is the data-gathering half of `bladestan:generate-signatures`. It runs
 * inside a normal PHPStan analysis (registered through
 * config/generate-signatures.neon) and reuses the same call-site matchers as
 * {@see \Bladestan\Rules\ViewCallSiteRule}, so every render form the validator
 * understands (`view()`, `View::make()`, `->view()`/`->markdown()`, Mailable
 * content) is covered by the generator too. Each collected entry is one render
 * site: the view name plus a variable => PHPDoc-type map, with the type printed
 * fully qualified so a written signature needs no `use` import.
 *
 * {@see ViewSignatureCollectedDataRule} aggregates every site of a view.
 *
 * @implements Collector<CallLike, list<array{view: string, variables: array<string, string>, line: int}>>
 * @see \Bladestan\Tests\Console\Extraction\ViewSignatureCollectedDataRuleTest
 */
final class ViewDataCollector implements Collector
{
    public function __construct(
        private readonly BladeViewMethodsMatcher $bladeViewMethodsMatcher,
        private readonly LaravelViewFunctionMatcher $laravelViewFunctionMatcher,
        private readonly MailablesContentMatcher $mailablesContentMatcher,
        private readonly TypeStringValidator $typeStringValidator,
    ) {
    }

    public function getNodeType(): string
    {
        return CallLike::class;
    }

    /**
     * @param CallLike $node
     * @return list<array{view: string, variables: array<string, string>, line: int}>|null
     * @throws ValueError
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        $renderTemplatesWithParameters = match (true) {
            $node instanceof StaticCall,
            $node instanceof FuncCall => $this->laravelViewFunctionMatcher->match($node, $scope),
            $node instanceof MethodCall => $this->bladeViewMethodsMatcher->match($node, $scope),
            $node instanceof New_ => $this->mailablesContentMatcher->match($node, $scope),
            default => [],
        };

        $sites = [];
        foreach ($renderTemplatesWithParameters as $renderTemplateWithParameter) {
            $variables = [];
            foreach ($renderTemplateWithParameter->parametersArray as $variableName => $type) {
                $variables[$variableName] = $this->describeType($type);
            }

            $sites[] = [
                'view' => $renderTemplateWithParameter->templateName,
                'variables' => $variables,
                'line' => $node->getStartLine(),
            ];
        }

        return $sites === [] ? null : $sites;
    }

    /**
     * Print a type as parser-safe PHPDoc suited to a reusable signature.
     *
     * The type is first generalized, so a value passed as the literal
     * `'Hello World'` becomes `string` rather than a signature that only that
     * one string satisfies, while array shapes and generics are kept. The
     * precise description is used when PHPStan's own PHPDoc parser accepts it;
     * when it prints something the parser rejects (an accessory type such as
     * `hasOffsetValue(...)`, an unresolved template placeholder) it falls back
     * to the coarser type-only description and, failing that, to `mixed`. Every
     * branch is a description PHPStan produced, so no string rewriting is
     * needed.
     */
    private function describeType(Type $type): string
    {
        $type = $type->generalize(GeneralizePrecision::lessSpecific());

        foreach ([VerbosityLevel::precise(), VerbosityLevel::typeOnly()] as $verbosityLevel) {
            $described = $type->describe($verbosityLevel);
            if ($this->typeStringValidator->isValid($described)) {
                return $described;
            }
        }

        return 'mixed';
    }
}
