<?php

declare(strict_types=1);

namespace Bladestan\NodeAnalyzer;

use Bladestan\ValueObject\RenderTemplateWithParameters;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Type\ObjectType;
use ValueError;

/**
 * Render sites the project declares itself: a class that wraps view() behind
 * its own constructor.
 *
 * A project that renders through `new SomeViewResponse($name, $data)` hides the
 * template name from the view() call inside, where it is only a parameter, so
 * every such call site is invisible. Naming the class, and which constructor
 * argument carries the name and the data, makes it a render site like any
 * other.
 *
 * Subclasses count: a PDF variant extending the base response renders the same
 * way.
 */
final class ConfiguredRenderSiteMatcher
{
    /**
     * @param list<array{class: string, viewNameArg: int, dataArg: int}> $renderSiteClasses
     */
    public function __construct(
        private readonly ViewDataParametersAnalyzer $viewDataParametersAnalyzer,
        private readonly array $renderSiteClasses,
    ) {
    }

    /**
     * @return list<RenderTemplateWithParameters>
     *
     * @throws ValueError
     */
    public function match(New_ $new, Scope $scope): array
    {
        if (! $new->class instanceof Name) {
            return [];
        }

        $instantiated = new ObjectType((string) $new->class);
        foreach ($this->renderSiteClasses as $renderSite) {
            if (! (new ObjectType($renderSite['class']))->isSuperTypeOf($instantiated)->yes()) {
                continue;
            }

            $template = $this->matchOne($new, $scope, $renderSite['viewNameArg'], $renderSite['dataArg']);
            if ($template instanceof RenderTemplateWithParameters) {
                return [$template];
            }
        }

        return [];
    }

    /**
     * @throws ValueError
     */
    private function matchOne(New_ $new, Scope $scope, int $viewNameArg, int $dataArg): ?RenderTemplateWithParameters
    {
        $arguments = $new->getArgs();
        $viewName = $arguments[$viewNameArg] ?? null;
        if ($viewName === null || ! $viewName->value instanceof String_) {
            return null;
        }

        $parameters = [];
        $hasUnresolvedData = false;
        $data = $arguments[$dataArg] ?? null;
        if ($data !== null) {
            $resolved = $this->viewDataParametersAnalyzer->resolveParametersArray($data, $scope);
            $parameters = $resolved->parameters;
            $hasUnresolvedData = ! $resolved->resolved;
        }

        return new RenderTemplateWithParameters($viewName->value->value, $parameters, false, $hasUnresolvedData);
    }
}
