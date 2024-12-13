<?php

declare(strict_types=1);

namespace TomasVotruba\Bladestan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use TomasVotruba\Bladestan\NodeAnalyzer\BladeViewMethodsMatcher;
use TomasVotruba\Bladestan\NodeAnalyzer\LaravelViewFunctionMatcher;
use TomasVotruba\Bladestan\NodeAnalyzer\MailablesContentMatcher;
use TomasVotruba\Bladestan\TemplateCompiler\Rules\TemplateRulesRegistry;
use TomasVotruba\Bladestan\ViewRuleHelper;

/**
 * @implements Rule<Node>
 * @see \TomasVotruba\Bladestan\Tests\Rules\BladeRuleTest
 */
final class BladeRule implements Rule
{
    /**
     * @param list<Rule> $rules
     * @phpstan-ignore missingType.generics
     */
    public function __construct(
        array $rules,
        private readonly BladeViewMethodsMatcher $bladeViewMethodsMatcher,
        private readonly LaravelViewFunctionMatcher $laravelViewFunctionMatcher,
        private readonly MailablesContentMatcher $mailablesContentMatcher,
        private readonly ViewRuleHelper $viewRuleHelper
    ) {
        $this->viewRuleHelper->setRegistry(new TemplateRulesRegistry($rules));
    }

    public function getNodeType(): string
    {
        return CallLike::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if ($node instanceof StaticCall || $node instanceof FuncCall) {
            return $this->processLaravelViewFunction($node, $scope);
        }

        if ($node instanceof MethodCall) {
            return $this->processBladeView($node, $scope);
        }

        if ($node instanceof New_) {
            return $this->processMailablesContent($node, $scope);
        }

        return [];
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function processMailablesContent(New_ $new, Scope $scope): array
    {
        $renderTemplatesWithParameters = $this->mailablesContentMatcher->match($new, $scope);

        return $this->viewRuleHelper->processNode($new, $scope, $renderTemplatesWithParameters);
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function processLaravelViewFunction(FuncCall|StaticCall $callLike, Scope $scope): array
    {
        $renderTemplatesWithParameters = $this->laravelViewFunctionMatcher->match($callLike, $scope);

        return $this->viewRuleHelper->processNode($callLike, $scope, $renderTemplatesWithParameters);
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function processBladeView(MethodCall $methodCall, Scope $scope): array
    {
        $renderTemplatesWithParameters = $this->bladeViewMethodsMatcher->match($methodCall, $scope);

        return $this->viewRuleHelper->processNode($methodCall, $scope, $renderTemplatesWithParameters);
    }
}
