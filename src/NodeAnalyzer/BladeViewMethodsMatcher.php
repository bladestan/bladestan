<?php

declare(strict_types=1);

namespace Bladestan\NodeAnalyzer;

use Bladestan\TemplateCompiler\ValueObject\RenderTemplateWithParameters;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Contracts\View\Factory as ViewFactoryContract;
use Illuminate\Http\Response;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\HtmlString;
use Illuminate\View\Component;
use Illuminate\View\ComponentAttributeBag;
use Illuminate\View\Factory;
use InvalidArgumentException;
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
use PHPStan\Type\Type;
use PHPStan\Type\UnionType;

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
     * @throws InvalidArgumentException
     */
    public function match(MethodCall $methodCall, Scope $scope): ?RenderTemplateWithParameters
    {
        $methodName = $this->resolveName($methodCall);
        if ($methodName === null) {
            return null;
        }

        $calledOnType = $scope->getType($methodCall->var);

        if (! $this->isCalledOnTypeABladeView($calledOnType, $methodName)) {
            return null;
        }

        $templateNameArg = $this->findTemplateNameArg($methodName, $methodCall);
        if (! $templateNameArg instanceof Arg) {
            return null;
        }

        $template = $templateNameArg->value;

        $resolvedTemplateFilePath = $this->templateFilePathResolver->resolveExistingFilePath($template, $scope);
        if ($resolvedTemplateFilePath === null) {
            return null;
        }

        $arg = $this->findTemplateDataArgument($methodName, $methodCall);

        if (! $arg instanceof Arg) {
            $parametersArray = new Array_();
        } else {
            $parametersArray = $this->viewDataParametersAnalyzer->resolveParametersArray($arg, $scope);
        }

        if ((new ObjectType(Component::class))->isSuperTypeOf($calledOnType)->yes()) {
            $type = new New_(new FullyQualified(HtmlString::class));
            $parametersArray->items[] = new ArrayItem($type, new String_('slot'));
            $type = new New_(new FullyQualified(ComponentAttributeBag::class));
            $parametersArray->items[] = new ArrayItem($type, new String_('attributes'));
        }

        return new RenderTemplateWithParameters($resolvedTemplateFilePath, $parametersArray);
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
        return (new UnionType([
            new ObjectType(ResponseFactory::class),
            new ObjectType(Response::class),
            new ObjectType(Component::class),
            new ObjectType(Mailable::class),
            new ObjectType(MailMessage::class),
        ]))->isSuperTypeOf($objectType)
            ->yes();
    }

    private function isCalledOnTypeABladeView(Type $objectType, string $methodName): bool
    {
        if ((new ObjectType(Factory::class))->isSuperTypeOf($objectType)->yes()) {
            return in_array($methodName, self::VIEW_FACTORY_METHOD_NAMES, true);
        }

        if ((new ObjectType(ViewFactoryContract::class))->isSuperTypeOf($objectType)->yes()) {
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
