<?php

declare(strict_types=1);

namespace Bladestan\NodeAnalyzer;

use Bladestan\TemplateCompiler\ValueObject\RenderTemplateWithParameters;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Contracts\View\Factory as ViewFactoryContract;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\HtmlString;
use Illuminate\View\Component;
use Illuminate\View\ComponentAttributeBag;
use Illuminate\View\Factory;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Type\ObjectType;
use PHPStan\Type\ThisType;
use PHPStan\Type\Type;

final class BladeViewMethodsMatcher
{
    /**
     * @var string
     */
    public const VIEW = 'view';

    /**
     * @var string
     */
    private const MAKE = 'make';

    /**
     * @var string[]
     */
    private const VIEW_FACTORY_METHOD_NAMES = ['make', 'renderWhen', 'renderUnless'];

    public function __construct(
        private readonly TemplateFilePathResolver $templateFilePathResolver,
        private readonly ViewDataParametersAnalyzer $viewDataParametersAnalyzer
    ) {
    }

    /**
     * @return RenderTemplateWithParameters[]
     */
    public function match(MethodCall $methodCall, Scope $scope): array
    {
        $methodName = $this->resolveName($methodCall);
        if ($methodName === null) {
            return [];
        }

        $calledOnType = $scope->getType($methodCall->var);

        // narrow response
        if ($calledOnType instanceof ThisType) {
            $calledOnType = $calledOnType->getStaticObjectType();
        }

        if (! $this->isCalledOnTypeABladeView($calledOnType, $methodName)) {
            return [];
        }

        $templateNameArg = $this->findTemplateNameArg($methodName, $methodCall);
        if (! $templateNameArg instanceof Arg) {
            return [];
        }

        $template = $templateNameArg->value;

        $resolvedTemplateFilePaths = $this->templateFilePathResolver->resolveExistingFilePaths($template, $scope);
        if ($resolvedTemplateFilePaths === []) {
            return [];
        }

        $arg = $this->findTemplateDataArgument($methodName, $methodCall);

        if (! $arg instanceof Arg) {
            $parametersArray = new Array_();
        } else {
            $parametersArray = $this->viewDataParametersAnalyzer->resolveParametersArray($arg, $scope);
        }

        /** @phpstan-ignore phpstanApi.instanceofType */
        if ($calledOnType instanceof ObjectType && $calledOnType->isInstanceOf(Component::class)->yes()) {
            $type = new New_(new FullyQualified(HtmlString::class));
            $parametersArray->items[] = new ArrayItem($type, new String_('slot'));
            $type = new New_(new FullyQualified(ComponentAttributeBag::class));
            $parametersArray->items[] = new ArrayItem($type, new String_('attributes'));
        }

        $result = [];

        foreach ($resolvedTemplateFilePaths as $resolvedTemplateFilePath) {
            $result[] = new RenderTemplateWithParameters($resolvedTemplateFilePath, $parametersArray);
        }

        return $result;
    }

    private function resolveName(MethodCall $methodCall): ?string
    {
        if (! $methodCall->name instanceof Identifier) {
            return null;
        }

        return $methodCall->name->name;
    }

    private function isClassWithViewMethod(Type $objectType): bool
    {
        if ($objectType->isSuperTypeOf(new ObjectType(ResponseFactory::class))->yes()) {
            return true;
        }

        /** @phpstan-ignore phpstanApi.instanceofType */
        if (! $objectType instanceof ObjectType) {
            return false;
        }

        if ($objectType->isInstanceOf(Component::class)->yes()) {
            return true;
        }

        if ($objectType->isInstanceOf(Mailable::class)->yes()) {
            return true;
        }

        return $objectType->isInstanceOf(MailMessage::class)->yes();
    }

    private function isCalledOnTypeABladeView(Type $objectType, string $methodName): bool
    {
        if ($objectType->isSuperTypeOf(new ObjectType(Factory::class))->yes()) {
            return in_array($methodName, self::VIEW_FACTORY_METHOD_NAMES, true);
        }

        if ($objectType->isSuperTypeOf(new ObjectType(ViewFactoryContract::class))->yes()) {
            return $methodName === self::MAKE;
        }

        if ($this->isClassWithViewMethod($objectType)) {
            return $methodName === self::VIEW;
        }

        return false;
    }

    private function findTemplateNameArg(string $methodName, MethodCall $methodCall): ?Arg
    {
        $args = $methodCall->getArgs();

        if ($args === []) {
            return null;
        }

        // Those methods take the view name as the first argument
        if ($methodName === self::MAKE || $methodName === self::VIEW) {
            return $args[0];
        }

        // Here it can just be `renderWhen` or `renderUnless`
        if (count($args) < 2) {
            return null;
        }

        // Second argument is the template name
        return $args[1];
    }

    private function findTemplateDataArgument(string $methodName, MethodCall $methodCall): ?Arg
    {
        $args = $methodCall->getArgs();

        if (count($args) < 2) {
            return null;
        }

        if ($methodName === self::VIEW) {
            return $args[1];
        }

        // `make` just takes view name and data as arguments
        if ($methodName === self::MAKE) {
            return $args[1];
        }

        // Here it can just be `renderWhen` or `renderUnless`
        if (count($args) < 3) {
            return null;
        }

        // Second argument is the template data
        return $args[2];
    }
}
