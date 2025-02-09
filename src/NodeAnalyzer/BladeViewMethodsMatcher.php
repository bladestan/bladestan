<?php

declare(strict_types=1);

namespace Bladestan\NodeAnalyzer;

use Bladestan\TemplateCompiler\TypeAnalyzer\TemplateVariableTypesResolver;
use Bladestan\TemplateCompiler\ValueObject\RenderTemplateWithParameters;
use Bladestan\TemplateCompiler\ValueObject\VariableAndType;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Contracts\View\Factory as ViewFactoryContract;
use Illuminate\Http\Response;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\HtmlString;
use Illuminate\View\Component;
use Illuminate\View\ComponentAttributeBag;
use Illuminate\View\Factory as ViewFactory;
use InvalidArgumentException;
use Livewire\Component as LivewireComponent;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Type\MixedType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\UnionType;

final class BladeViewMethodsMatcher
{
    private const string VIEW = 'view';

    private const string MAKE = 'make';

    private const string FIRST = 'first';

    private const string EACH = 'renderEach';

    private const string WHEN = 'renderWhen';

    private const string UNLESS = 'renderUnless';

    /**
     * @var list<string>
     */
    private const VIEW_FACTORY_METHOD_NAMES = [self::MAKE, self::WHEN, self::UNLESS, self::FIRST, self::EACH];

    public function __construct(
        private readonly TemplateFilePathResolver $templateFilePathResolver,
        private readonly ViewDataParametersAnalyzer $viewDataParametersAnalyzer,
        private readonly TemplateVariableTypesResolver $templateVariableTypesResolver,
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

        if ($methodName === self::EACH) {
            $parametersArray = $this->getEachVariables($methodCall, $scope);
        } else {
            $parametersArray = [];

            $arg = $this->findTemplateDataArgument($methodName, $methodCall);
            if ($arg instanceof Arg) {
                $parametersArray = $this->viewDataParametersAnalyzer->resolveParametersArray($arg, $scope);
                $parametersArray = $this->templateVariableTypesResolver->resolveArray($parametersArray, $scope);
            }
        }

        if ((new ObjectType(Component::class))->isSuperTypeOf($calledOnType)->yes()) {
            $parametersArray[] = new VariableAndType('attributes', new ObjectType(ComponentAttributeBag::class));
            $parametersArray[] = new VariableAndType('slot', new ObjectType(HtmlString::class));
        }

        if ((new ObjectType(LivewireComponent::class))->isSuperTypeOf($calledOnType)->yes()) {
            $objectType = new ObjectType($calledOnType->getObjectClassReflections()[0]->getName());
            $parametersArray[] = new VariableAndType('__livewire', $objectType);
            $parametersArray[] = new VariableAndType('_instance', $objectType);
            $parametersArray[] = new VariableAndType('this', $objectType);
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
        if ((new ObjectType(ViewFactory::class))->isSuperTypeOf($objectType)->yes()) {
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

    /**
     * @return list<VariableAndType>
     */
    private function getEachVariables(MethodCall $methodCall, Scope $scope): array
    {
        $values = [];

        $args = $methodCall->getArgs();

        $valueName = null;
        if ($args[2]->value instanceof String_) {
            $valueName = $args[2]->value->value;
        }

        $type = $scope->getType($args[1]->value);
        $constArray = $type->getConstantArrays() ?: $type->getArrays();
        if (count($constArray) === 1) {
            $constArray = $constArray[0];
            $values[] = new VariableAndType('key', $constArray->getKeyType());
            if ($valueName) {
                $values[] = new VariableAndType($valueName, $constArray->getItemType());
            }
        } else {
            $values[] = new VariableAndType('key', new MixedType());
            if ($valueName) {
                $values[] = new VariableAndType($valueName, new MixedType());
            }
        }

        return $values;
    }

    private function findTemplateNameArg(string $methodName, MethodCall $methodCall): ?Arg
    {
        $args = $methodCall->getArgs();

        if ($args === []) {
            return null;
        }

        if ($methodName === self::VIEW || $methodName === self::MAKE || $methodName === self::EACH) {
            return $args[0];
        }

        if ($methodName === self::FIRST && $args[0]->value instanceof Array_) {
            // The last template is likely the safe fallback so use that so we don't complain about the optionals
            $last = end($args[0]->value->items);
            if (! $last) {
                return null;
            }

            return new Arg($last->value);
        }

        if ($methodName === self::WHEN || $methodName === self::UNLESS) {
            return $args[1];
        }

        return null;
    }

    private function findTemplateDataArgument(string $methodName, MethodCall $methodCall): ?Arg
    {
        $args = $methodCall->getArgs();

        if (count($args) < 2) {
            return null;
        }

        if ($methodName === self::VIEW || $methodName === self::MAKE || $methodName === self::FIRST) {
            return $args[1];
        }

        if ($methodName === self::WHEN || $methodName === self::UNLESS) {
            return $args[2];
        }

        return null;
    }
}
