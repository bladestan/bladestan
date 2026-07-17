<?php

declare(strict_types=1);

namespace Bladestan\NodeAnalyzer;

use Bladestan\ValueObject\RenderTemplateWithParameters;
use Illuminate\Support\Facades\Response as ResponseFacades;
use Illuminate\Support\Facades\View;
use Illuminate\View\Component;
use Livewire\Component as LivewireComponent;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use ValueError;

final class LaravelViewFunctionMatcher
{
    public function __construct(
        private readonly ViewDataParametersAnalyzer $viewDataParametersAnalyzer,
        private readonly MagicViewWithCallParameterResolver $magicViewWithCallParameterResolver,
        private readonly ClassPropertiesResolver $classPropertiesResolver,
    ) {
    }

    /**
     * @return list<RenderTemplateWithParameters>
     *
     * @throws ValueError
     */
    public function match(FuncCall|StaticCall $callLike, Scope $scope): array
    {
        // view('', []);
        if ($callLike instanceof FuncCall
            && $callLike->name instanceof Name
            && $scope->resolveName($callLike->name) === 'view'
        ) {
            return $this->matchView($callLike, $scope);
        }

        // View::make('', []);
        // ResponseFacades::view('', []);
        if ($callLike instanceof StaticCall
            && $callLike->class instanceof Name
            && $callLike->name instanceof Identifier
            && ((string) $callLike->class === View::class && (string) $callLike->name === 'make'
                || (string) $callLike->class === ResponseFacades::class && (string) $callLike->name === 'view')
        ) {
            return $this->matchView($callLike, $scope);
        }

        return [];
    }

    /**
     * @return list<RenderTemplateWithParameters>
     *
     * @throws ValueError
     */
    private function matchView(FuncCall|StaticCall $callLike, Scope $scope): array
    {
        if (count($callLike->getArgs()) < 1) {
            return [];
        }

        $template = $callLike->getArgs()[0]
            ->value;
        if (! $template instanceof String_) {
            return [];
        }

        $args = $callLike->getArgs();

        $parametersArray = $this->magicViewWithCallParameterResolver->resolve($callLike, $scope);

        if (count($args) >= 2) {
            $parametersArray += $this->viewDataParametersAnalyzer->resolveParametersArray($args[1], $scope);
        }

        // Only a component's template receives the enclosing class's public
        // properties: Livewire merges them into the render view, and a class
        // component's view is rendered with the component's data(). A plain
        // controller's properties never reach the view, so folding them there
        // would hide genuinely missing parameters.
        if ($scope->isInClass()) {
            $classReflection = $scope->getClassReflection();
            if ($classReflection->is(Component::class) || $classReflection->is(LivewireComponent::class)) {
                $parametersArray += $this->classPropertiesResolver->resolve($classReflection, $scope);
            }
        }

        // view($name, $data, get_defined_vars()) forwards the surrounding
        // scope as $mergeData — this is what compiled @include calls emit.
        $forwardsScope = isset($args[2])
            && $args[2]->value instanceof FuncCall
            && $args[2]->value->name instanceof Name
            && $args[2]->value->name->toLowerString() === 'get_defined_vars';

        return [new RenderTemplateWithParameters($template->value, $parametersArray, $forwardsScope)];
    }
}
