<?php

declare(strict_types=1);

namespace Bladestan\NodeAnalyzer;

use Bladestan\TemplateCompiler\ValueObject\RenderTemplateWithParameters;
use Illuminate\Support\Facades\Response as ResponseFacades;
use Illuminate\Support\Facades\View;
use InvalidArgumentException;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;

final class LaravelViewFunctionMatcher
{
    public function __construct(
        private readonly TemplateFilePathResolver $templateFilePathResolver,
        private readonly ViewDataParametersAnalyzer $viewDataParametersAnalyzer,
        private readonly MagicViewWithCallParameterResolver $magicViewWithCallParameterResolver,
        private readonly ClassPropertiesResolver $classPropertiesResolver,
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
        // ResponseFacades::view('', []);
        if ($callLike instanceof StaticCall
            && $callLike->class instanceof Name
            && $callLike->name instanceof Identifier
            && ((string) $callLike->class === View::class && (string) $callLike->name === 'make'
                || (string) $callLike->class === ResponseFacades::class && (string) $callLike->name === 'view')
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

        $parametersArray = $this->magicViewWithCallParameterResolver->resolve($callLike, $scope);

        if (count($args) === 2) {
            $parametersArray += $this->viewDataParametersAnalyzer->resolveParametersArray($args[1], $scope);
        }

        if ($scope->isInClass()) {
            $nativeReflection = $scope->getClassReflection();
            $parametersArray += $this->classPropertiesResolver->resolve($nativeReflection, $scope);
        }

        return new RenderTemplateWithParameters($resolvedTemplateFilePath, $parametersArray);
    }
}
