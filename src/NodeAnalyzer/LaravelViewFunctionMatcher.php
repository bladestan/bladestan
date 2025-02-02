<?php

declare(strict_types=1);

namespace Bladestan\NodeAnalyzer;

use Bladestan\TemplateCompiler\ValueObject\RenderTemplateWithParameters;
use Illuminate\Support\Facades\View;
use Illuminate\Support\HtmlString;
use Illuminate\View\Component;
use Illuminate\View\ComponentAttributeBag;
use InvalidArgumentException;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;

final class LaravelViewFunctionMatcher
{
    public function __construct(
        private readonly TemplateFilePathResolver $templateFilePathResolver,
        private readonly ViewDataParametersAnalyzer $viewDataParametersAnalyzer,
        private readonly MagicViewWithCallParameterResolver $magicViewWithCallParameterResolver
    ) {
    }

    /**
     * @throws InvalidArgumentException
     */
    public function match(FuncCall|StaticCall $callLike, Scope $scope): ?RenderTemplateWithParameters
    {
        // view('', []);
        if ($callLike instanceof FuncCall
            && $callLike->name instanceof Name
            && $scope->resolveName($callLike->name) === 'view'
        ) {
            // TODO: maybe make sure this function is coming from Laravel
            return $this->matchView($callLike, $scope);
        }

        // View::make('', []);
        if ($callLike instanceof StaticCall
            && $callLike->class instanceof Name
            && (string) $callLike->class === View::class
            && $callLike->name instanceof Identifier
            && (string) $callLike->name === 'make'
        ) {
            return $this->matchView($callLike, $scope);
        }

        return null;
    }

    /**
     * @throws InvalidArgumentException
     */
    private function matchView(FuncCall|StaticCall $callLike, Scope $scope): ?RenderTemplateWithParameters
    {
        if (count($callLike->getArgs()) < 1) {
            return null;
        }

        $template = $callLike->getArgs()[0]
            ->value;

        $resolvedTemplateFilePath = $this->templateFilePathResolver->resolveExistingFilePath($template, $scope);
        if ($resolvedTemplateFilePath === null) {
            return null;
        }

        $args = $callLike->getArgs();

        if (count($args) !== 2) {
            $parametersArray = new Array_();
        } else {
            $parametersArray = $this->viewDataParametersAnalyzer->resolveParametersArray($args[1], $scope);
        }

        $parametersArray->items = $this->magicViewWithCallParameterResolver->resolve(
            $callLike
        ) + $parametersArray->items;

        if ($scope->isInClass() && $scope->getClassReflection()->is(Component::class)) {
            $type = new New_(new FullyQualified(HtmlString::class));
            $parametersArray->items[] = new ArrayItem($type, new String_('slot'));
            $type = new New_(new FullyQualified(ComponentAttributeBag::class));
            $parametersArray->items[] = new ArrayItem($type, new String_('attributes'));
        }

        return new RenderTemplateWithParameters($resolvedTemplateFilePath, $parametersArray);
    }
}
