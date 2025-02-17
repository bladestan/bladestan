<?php

declare(strict_types=1);

namespace Bladestan\NodeAnalyzer;

use Bladestan\TemplateCompiler\ValueObject\RenderTemplateWithParameters;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Contracts\View\Factory as ViewFactoryContract;
use Illuminate\Http\Response;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Message;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\View\Component;
use Illuminate\View\Factory as ViewFactory;
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
    /**
     * @var string
     */
    private const VIEW = 'view';

    /**
     * @var string
     */
    private const MAKE = 'make';

    /**
     * @var string
     */
    private const FIRST = 'first';

    /**
     * @var string
     */
    private const EACH = 'renderEach';

    /**
     * @var string
     */
    private const WHEN = 'renderWhen';

    /**
     * @var string
     */
    private const UNLESS = 'renderUnless';

    /**
     * @var string
     */
    private const MARKDOWN = 'markdown';

    /**
     * @var string
     */
    private const TEXT = 'text';

    /**
     * @var string
     */
    private const HTML = 'html';

    /**
     * @var list<string>
     */
    private const VIEW_FACTORY_METHOD_NAMES = [self::MAKE, self::WHEN, self::UNLESS, self::FIRST, self::EACH];

    private const MAILABLE_METHOD_NAMES = [self::VIEW, self::HTML, self::MARKDOWN, self::TEXT];

    private const MAIL_METHOD_NAMES = [self::VIEW, self::MARKDOWN, self::TEXT];

    public function __construct(
        private readonly ViewDataParametersAnalyzer $viewDataParametersAnalyzer,
        private readonly MagicViewWithCallParameterResolver $magicViewWithCallParameterResolver,
        private readonly ClassPropertiesResolver $classPropertiesResolver,
    ) {
    }

    /**
     * @return list<RenderTemplateWithParameters>
     */
    public function match(MethodCall $methodCall, Scope $scope): array
    {
        $methodName = $this->resolveName($methodCall);
        if ($methodName === null) {
            return [];
        }

        $calledOnType = $scope->getType($methodCall->var);

        if (! $this->isCalledOnTypeABladeView($calledOnType, $methodName)) {
            return [];
        }

        $templateNameArg = $this->findTemplateNameArg($methodName, $methodCall);
        if (! $templateNameArg instanceof Arg || ! $templateNameArg->value instanceof String_) {
            return [];
        }

        $template = $templateNameArg->value->value;

        $parametersArray = $this->magicViewWithCallParameterResolver->resolve($methodCall, $scope);

        if ($this->isClassWithMessage($calledOnType)) {
            $parametersArray += [
                'message' => new ObjectType(Message::class),
            ];
        }

        if ($methodName === self::EACH) {
            $parametersArray += $this->getEachVariables($methodCall, $scope);
        } else {
            $arg = $this->findTemplateDataArgument($methodName, $methodCall);
            if ($arg instanceof Arg) {
                $parametersArray += $this->viewDataParametersAnalyzer->resolveParametersArray($arg, $scope);
            }
        }

        $nativeReflection = $calledOnType->getObjectClassReflections()[0];
        $parametersArray += $this->classPropertiesResolver->resolve($nativeReflection, $scope);

        return [new RenderTemplateWithParameters($template, $parametersArray)];
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
        ]))->isSuperTypeOf($objectType)
            ->yes();
    }

    private function isClassWithMessage(Type $objectType): bool
    {
        return (new UnionType([
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

        if ((new ObjectType(Mailable::class))->isSuperTypeOf($objectType)->yes()) {
            return in_array($methodName, self::MAILABLE_METHOD_NAMES, true);
        }

        if ((new ObjectType(MailMessage::class))->isSuperTypeOf($objectType)->yes()) {
            return in_array($methodName, self::MAIL_METHOD_NAMES, true);
        }

        if ($this->isClassWithViewMethod($objectType)) {
            return $methodName === self::VIEW;
        }

        return false;
    }

    /**
     * @return array<string, Type>
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
            $values['key'] = $constArray->getKeyType();
            if ($valueName) {
                $values[$valueName] = $constArray->getItemType();
            }
        } else {
            $values['key'] = new MixedType();
            if ($valueName) {
                $values[$valueName] = new MixedType();
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

        if ($methodName === self::VIEW
            || $methodName === self::MAKE
            || $methodName === self::EACH
            || $methodName === self::HTML
            || $methodName === self::MARKDOWN
            || $methodName === self::TEXT
        ) {
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

        if ($methodName === self::VIEW
            || $methodName === self::MAKE
            || $methodName === self::FIRST
            || $methodName === self::HTML
            || $methodName === self::MARKDOWN
            || $methodName === self::TEXT
        ) {
            return $args[1];
        }

        if ($methodName === self::WHEN || $methodName === self::UNLESS) {
            return $args[2];
        }

        return null;
    }
}
