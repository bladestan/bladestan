<?php

declare(strict_types=1);

namespace Bladestan\NodeAnalyzer;

use Bladestan\ValueObject\RenderTemplateWithParameters;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Message;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Type\ObjectType;
use ValueError;

final class MailablesContentMatcher
{
    public function __construct(
        private readonly ViewDataParametersAnalyzer $viewDataParametersAnalyzer,
        private readonly MagicViewWithCallParameterResolver $magicViewWithCallParameterResolver,
    ) {
    }

    /**
     * @return list<RenderTemplateWithParameters>
     *
     * @throws ValueError
     */
    public function match(New_ $new, Scope $scope): array
    {
        if (! $new->class instanceof Name || (string) $new->class !== Content::class) {
            return [];
        }

        // Content's constructor parameters in declaration order, so positional
        // arguments resolve to the same names as named ones.
        $constructorParameterNames = ['view', 'html', 'text', 'markdown', 'with', 'htmlString'];

        $viewNames = [];
        $resolvedWith = $this->magicViewWithCallParameterResolver->resolve($new, $scope);
        $parametersArray = $resolvedWith->parameters;
        $hasUnresolvedData = ! $resolvedWith->resolved;
        foreach ($new->getArgs() as $position => $argument) {
            $argName = $argument->name === null
                ? ($constructorParameterNames[$position] ?? '')
                : (string) $argument->name;
            if ($argument->value instanceof String_) {
                $value = $argument->value->value;
                if (in_array($argName, ['view', 'html', 'markdown', 'text'], true)) {
                    $viewNames[] = $value;
                }
            } elseif ($argName === 'with') {
                // The with: data complements the ->with() magic calls rather
                // than replacing them; on a name collision the explicit
                // constructor data wins.
                $resolvedParameters = $this->viewDataParametersAnalyzer->resolveParametersArray($argument, $scope);
                $parametersArray = $resolvedParameters->parameters + $parametersArray;
                $hasUnresolvedData = $hasUnresolvedData || ! $resolvedParameters->resolved;
            }
        }

        $parametersArray += [
            'message' => new ObjectType(Message::class),
        ];

        $templates = [];
        foreach ($viewNames as $viewName) {
            $templates[] = new RenderTemplateWithParameters($viewName, $parametersArray, false, $hasUnresolvedData);
        }

        return $templates;
    }
}
