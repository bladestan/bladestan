<?php

declare(strict_types=1);

namespace Bladestan\NodeAnalyzer;

use Bladestan\TemplateCompiler\ValueObject\RenderTemplateWithParameters;
use Illuminate\Mail\Mailables\Content;
use InvalidArgumentException;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;

final class MailablesContentMatcher
{
    public function __construct(
        private readonly TemplateFilePathResolver $templateFilePathResolver,
        private readonly ViewDataParametersAnalyzer $viewDataParametersAnalyzer,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     */
    public function match(New_ $new, Scope $scope): ?RenderTemplateWithParameters
    {
        if (! $new->class instanceof Name || (string) $new->class !== Content::class) {
            return null;
        }

        $viewName = null;
        $parametersArray = [];
        foreach ($new->getArgs() as $argument) {
            $argName = (string) $argument->name;
            if ($argName === 'view') {
                $viewName = $argument->value;
            } elseif ($argName === 'with') {
                $parametersArray = $this->viewDataParametersAnalyzer->resolveParametersArray($argument, $scope);
            }
        }

        if ($viewName === null) {
            return null;
        }

        $resolvedTemplateFilePath = $this->templateFilePathResolver->resolveExistingFilePath($viewName, $scope);
        if ($resolvedTemplateFilePath === null) {
            return null;
        }

        return new RenderTemplateWithParameters($resolvedTemplateFilePath, $parametersArray);
    }
}
