<?php

declare(strict_types=1);

namespace Bladestan\NodeAnalyzer;

use Bladestan\TemplateCompiler\ValueObject\RenderTemplateWithParameters;
use Illuminate\Mail\Mailables\Content;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;

final class MailablesContentMatcher
{
    public function __construct(
        private readonly ViewDataParametersAnalyzer $viewDataParametersAnalyzer,
        private readonly MagicViewWithCallParameterResolver $magicViewWithCallParameterResolver,
    ) {
    }

    public function match(New_ $new, Scope $scope): ?RenderTemplateWithParameters
    {
        if (! $new->class instanceof Name || (string) $new->class !== Content::class) {
            return null;
        }

        $viewName = null;
        $parametersArray = $this->magicViewWithCallParameterResolver->resolve($new, $scope);
        foreach ($new->getArgs() as $argument) {
            $argName = (string) $argument->name;
            if ($argName === 'view') {
                $viewName = $argument->value;
            } elseif ($argName === 'with') {
                $parametersArray = $this->viewDataParametersAnalyzer->resolveParametersArray($argument, $scope);
            }
        }

        if (! $viewName instanceof String_) {
            return null;
        }

        return new RenderTemplateWithParameters($viewName->value, $parametersArray);
    }
}
