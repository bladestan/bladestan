<?php

declare(strict_types=1);

namespace Bladestan\NodeAnalyzer;

use Bladestan\TemplateCompiler\ValueObject\RenderTemplateWithParameters;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Message;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Type\ObjectType;

final class MailablesContentMatcher
{
    public function __construct(
        private readonly ViewDataParametersAnalyzer $viewDataParametersAnalyzer,
        private readonly MagicViewWithCallParameterResolver $magicViewWithCallParameterResolver,
    ) {
    }

    /**
     * @return list<RenderTemplateWithParameters>
     */
    public function match(New_ $new, Scope $scope): array
    {
        if (! $new->class instanceof Name || (string) $new->class !== Content::class) {
            return [];
        }

        $viewNames = [];
        $parametersArray = $this->magicViewWithCallParameterResolver->resolve($new, $scope);
        foreach ($new->getArgs() as $argument) {
            $argName = (string) $argument->name;
            if ($argument->value instanceof String_) {
                $value = $argument->value->value;
                if (in_array($argName, ['view', 'html', 'markdown', 'text'], true)) {
                    $viewNames[] = $value;
                }
            } elseif ($argName === 'with') {
                $parametersArray = $this->viewDataParametersAnalyzer->resolveParametersArray($argument, $scope);
            }
        }

        $parametersArray += [
            'message' => new ObjectType(Message::class),
        ];

        $templates = [];
        foreach ($viewNames as $viewName) {
            $templates[] = new RenderTemplateWithParameters($viewName, $parametersArray);
        }

        return $templates;
    }
}
