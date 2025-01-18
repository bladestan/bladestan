<?php

declare(strict_types=1);

namespace Bladestan\Rules;

use Bladestan\ErrorReporting\Blade\TemplateErrorsFactory;
use Bladestan\NodeAnalyzer\BladeViewMethodsMatcher;
use Bladestan\NodeAnalyzer\LaravelViewFunctionMatcher;
use Bladestan\NodeAnalyzer\MailablesContentMatcher;
use Bladestan\TemplateCompiler\Rules\TemplateRulesRegistry;
use Bladestan\TemplateCompiler\ValueObject\RenderTemplateWithParameters;
use Bladestan\ViewRuleHelper;
use InvalidArgumentException;
use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
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
        private readonly ViewRuleHelper $viewRuleHelper,
        private readonly TemplateErrorsFactory $templateErrorsFactory,
    ) {
        $this->viewRuleHelper->setRegistry(new TemplateRulesRegistry($rules));
    }

    public function getNodeType(): string
    {
        return CallLike::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        assert($node instanceof CallLike);

        try {
            $renderTemplatesWithParameter = match (true) {
                $node instanceof StaticCall,
                $node instanceof FuncCall => $this->laravelViewFunctionMatcher->match($node, $scope),
                $node instanceof MethodCall => $this->bladeViewMethodsMatcher->match($node, $scope),
                $node instanceof New_ => $this->mailablesContentMatcher->match($node, $scope),
                default => null,
            };
        } catch (InvalidArgumentException $invalidArgumentException) {
            return [$this->templateErrorsFactory->createError(
                $invalidArgumentException->getMessage(),
                'bladestan.missing',
                $node->getLine(),
                $scope->getFile()
            )];
        }

        if (! $renderTemplatesWithParameter instanceof RenderTemplateWithParameters) {
            return [];
        }

        return $this->viewRuleHelper->processNode($node, $scope, $renderTemplatesWithParameter);
    }
}
