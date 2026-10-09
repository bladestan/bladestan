<?php

declare(strict_types=1);

namespace Bladestan\NodeAnalyzer;

use Bladestan\ValueObject\RenderTemplateWithParameters;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use ValueError;

/**
 * Recognises every call form that renders a template and resolves the
 * parameters it passes: `view()`/`View::make()` and the other view-factory
 * methods, `->view()`/`->markdown()`/`->text()` on a Mailable, and a
 * `new Content(...)` mail body.
 *
 * A single entry point so the call-site validator and the signature generator
 * agree on what a render site is: both feed AST nodes here and get back the
 * same {@see RenderTemplateWithParameters} list.
 */
final class RenderSiteMatcher
{
    public function __construct(
        private readonly LaravelViewFunctionMatcher $laravelViewFunctionMatcher,
        private readonly BladeViewMethodsMatcher $bladeViewMethodsMatcher,
        private readonly MailablesContentMatcher $mailablesContentMatcher,
    ) {
    }

    /**
     * @return list<RenderTemplateWithParameters>
     *
     * @throws ValueError
     */
    public function match(Node $node, Scope $scope): array
    {
        return match (true) {
            $node instanceof StaticCall,
            $node instanceof FuncCall => $this->laravelViewFunctionMatcher->match($node, $scope),
            $node instanceof MethodCall => $this->bladeViewMethodsMatcher->match($node, $scope),
            $node instanceof New_ => $this->mailablesContentMatcher->match($node, $scope),
            default => [],
        };
    }
}
