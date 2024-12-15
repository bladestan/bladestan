<?php

declare(strict_types=1);

namespace Bladestan\Rules;

use Bladestan\NodeAnalyzer\BladeViewMethodsMatcher;
use Bladestan\NodeAnalyzer\LaravelViewFunctionMatcher;
use Bladestan\NodeAnalyzer\MailablesContentMatcher;
use Bladestan\TemplateCompiler\Rules\TemplateRulesRegistry;
use Bladestan\ViewRuleHelper;
use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;

/**
 * @implements Rule<Node>
 * @see \Bladestan\Tests\Rules\BladeRuleTest
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
