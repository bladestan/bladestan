<?php

declare(strict_types=1);

namespace Bladestan\Compiler;

use Bladestan\PhpParser\ArrayStringToArrayConverter;
use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Str;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Component;
use Illuminate\View\ComponentAttributeBag;
use Illuminate\View\ComponentSlot;
use Livewire\Component as LivewireComponent;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use Throwable;

/**
 * Determines the extra variables in scope inside a component template's body,
 * so a standalone-compiled component is analyzed with the same scope Blade
 * gives it at runtime.
 *
 * A template signature declares what a caller must pass; it says nothing about
 * the variables Blade injects into a component body. Those are `$attributes`,
 * `$slot`, `$componentName`, every `@props` variable, and (for a class
 * component) the class's public properties and zero-argument public methods,
 * which Blade merges into the view. Without them, a component body that reads
 * `{{ $slot }}`, `{{ $attributes->merge(...) }}`, or `{{ $order->total }}`
 * reports undefined variables that no signature could fix.
 *
 * A template is treated as a component when it declares `@props` or its view
 * name is a component view (the `components.` convention or a registered
 * component namespace). When the view resolves to a component class, its public
 * members are added too. Types returned here are parser-safe PHPDoc strings.
 *
 * @see \Bladestan\Tests\Compiler\ComponentScopeResolverTest
 */
final class ComponentScopeResolver
{
    /**
     * @see https://regex101.com/r/O0eirb/1
     * @var string
     */
    private const PROPS_REGEX = '/@props\s*\(\s*(\[.*?\])\s*\)/s';

    /**
     * Component methods Blade never exposes as view variables. Framework methods
     * declared on Component itself are excluded by their declaring class; these
     * are the ones a component commonly overrides, so they need naming.
     *
     * @var list<string>
     */
    private const IGNORED_METHODS = ['render', 'resolveView', 'shouldRender', 'view', 'data', 'withName', 'withAttributes'];

    private readonly BladeInertRegionMasker $bladeInertRegionMasker;

    public function __construct(
        private readonly BladeCompiler $bladeCompiler,
        private readonly ArrayStringToArrayConverter $arrayStringToArrayConverter,
    ) {
        $this->bladeInertRegionMasker = new BladeInertRegionMasker();
    }

    /**
     * The variables Blade adds to a component body as PHPDoc type strings, or an
     * empty array when the template is not a component.
     *
     * @return array<string, string>
     */
    public function resolve(string $viewName, string $bladeContent): array
    {
        // Livewire component views get $this, $_instance, and $__livewire (all
        // the component instance) plus the component's public properties, which
        // Livewire exposes to the view as plain variables.
        $livewireClass = $this->resolveLivewireClass($viewName);
        if ($livewireClass !== null) {
            return $this->livewireScope($livewireClass);
        }

        $props = $this->extractProps($bladeContent);
        if ($props === null && ! $this->isComponentView($viewName)) {
            return [];
        }

        // Reflected class members are the lowest priority: an explicit @props
        // entry (below) or the template's signature (in the compiler) wins.
        $scope = [];
        $backingClass = $this->resolveBackingClass($viewName);
        if ($backingClass !== null) {
            $scope = $this->reflectMembers($backingClass);
        }

        $scope['slot'] = '\\' . ComponentSlot::class;
        $scope['componentName'] = 'string';

        // `$attributes` is a component-body variable like `$slot`, so the scope
        // declares it authoritatively rather than leaning on the compiled
        // `@props` output. Blade's `@props` block opens with
        // `$attributes ??= new ComponentAttributeBag()`, which types it in the
        // common case, but that shape is version-dependent; declaring it here
        // keeps `$attributes` typed regardless. The signature still wins, so an
        // author who needs a narrower type can override it.
        $scope['attributes'] = '\\' . ComponentAttributeBag::class;

        if ($props === null) {
            return $scope;
        }

        foreach ($props as $name => $defaultExpression) {
            $scope[$name] = $this->typeFromDefaultExpression($defaultExpression);
        }

        return $scope;
    }

    /**
     * The signature a component's `@props` declares: each prop name mapped to the
     * type of its default value, or `mixed` when it has no default (or a null
     * default). This is what `bladestan:generate-signatures` scaffolds for an
     * anonymous component, giving the author the exact prop list to type. Returns
     * null when the template has no `@props` directive.
     *
     * @return array<string, string>|null
     */
    public function propsSignature(string $bladeContent): ?array
    {
        $props = $this->extractProps($bladeContent);
        if ($props === null) {
            return null;
        }

        $signature = [];
        foreach ($props as $name => $defaultExpression) {
            $signature[$name] = $this->typeFromDefaultExpression($defaultExpression);
        }

        return $signature;
    }

    /**
     * Parse a `@props([...])` directive into prop name => default expression
     * (null when the prop is declared without a default). Returns null when the
     * template has no `@props` directive.
     *
     * @return array<string, string|null>|null
     */
    private function extractProps(string $bladeContent): ?array
    {
        // A @props inside a comment or @verbatim block is inert to Blade, so
        // mask those regions before scanning for the real declaration.
        $bladeContent = $this->bladeInertRegionMasker->mask($bladeContent);

        if (preg_match(self::PROPS_REGEX, $bladeContent, $matches) !== 1) {
            return null;
        }

        $converted = $this->arrayStringToArrayConverter->convert($matches[1]);

        $props = [];
        foreach ($converted as $key => $value) {
            if (is_string($key)) {
                // 'type' => 'info' — a prop with a default expression.
                $props[$key] = $value;
                continue;
            }

            // 'title' — a prop name with no default; the value is the quoted name.
            $name = trim($value, '\'"');
            if ($name !== '') {
                $props[$name] = null;
            }
        }

        return $props;
    }

    private function isComponentView(string $viewName): bool
    {
        if (str_starts_with($viewName, 'components.')) {
            return true;
        }

        foreach ($this->bladeCompiler->getAnonymousComponentNamespaces() as $prefix => $directory) {
            if (! is_string($prefix)) {
                continue;
            }

            if (! is_string($directory)) {
                continue;
            }

            $directoryPrefix = str_replace('/', '.', trim($directory, '/')) . '.';
            if (str_starts_with($viewName, $prefix . '::') || str_starts_with($viewName, $directoryPrefix)) {
                return true;
            }
        }

        foreach (array_keys($this->bladeCompiler->getClassComponentNamespaces()) as $prefix) {
            if (is_string($prefix) && str_starts_with($viewName, $prefix . '::')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve the component class backing a view, mirroring Laravel's own
     * resolution over the registered class-component namespaces and the default
     * `App\View\Components` convention, so casing matches the class on disk.
     *
     * @return class-string<Component>|null
     */
    private function resolveBackingClass(string $viewName): ?string
    {
        $classNamespaces = $this->bladeCompiler->getClassComponentNamespaces();

        // A class-component namespace addressed directly (backoffice::orders.row).
        foreach ($classNamespaces as $prefix => $namespace) {
            if (is_string($prefix) && is_string($namespace) && str_starts_with($viewName, $prefix . '::')) {
                $class = $this->buildComponentClass($namespace, substr($viewName, strlen($prefix) + 2));
                if ($class !== null) {
                    return $class;
                }
            }
        }

        // A view under a registered anonymous-component directory, resolved to
        // the class namespace sharing its prefix (Blade::componentNamespace).
        foreach ($this->bladeCompiler->getAnonymousComponentNamespaces() as $prefix => $directory) {
            if (! is_string($prefix)) {
                continue;
            }

            if (! is_string($directory)) {
                continue;
            }

            $classNamespace = $classNamespaces[$prefix] ?? null;
            if (! is_string($classNamespace)) {
                continue;
            }

            $rest = $this->viewNameUnderNamespace($viewName, $prefix, $directory);
            if ($rest === null) {
                continue;
            }

            $class = $this->buildComponentClass($classNamespace, $rest);
            if ($class !== null) {
                return $class;
            }
        }

        // Default convention: components.alert -> App\View\Components\Alert.
        if (str_starts_with($viewName, 'components.')) {
            return $this->buildComponentClass(
                $this->applicationNamespace() . 'View\\Components',
                substr($viewName, strlen('components.')),
            );
        }

        return null;
    }

    private function viewNameUnderNamespace(string $viewName, string $prefix, string $directory): ?string
    {
        if (str_starts_with($viewName, $prefix . '::')) {
            return substr($viewName, strlen($prefix) + 2);
        }

        $directoryPrefix = str_replace('/', '.', trim($directory, '/')) . '.';
        if ($directoryPrefix !== '.' && str_starts_with($viewName, $directoryPrefix)) {
            return substr($viewName, strlen($directoryPrefix));
        }

        return null;
    }

    /**
     * @template T of object
     * @param class-string<T> $baseClass
     * @return class-string<T>|null
     */
    private function buildComponentClass(string $namespace, string $componentName, string $baseClass = Component::class): ?string
    {
        $pieces = array_map(
            static fn (string $piece): string => ucfirst(Str::camel($piece)),
            explode('.', $componentName),
        );
        $class = trim($namespace, '\\') . '\\' . implode('\\', $pieces);

        if (class_exists($class) && is_subclass_of($class, $baseClass)) {
            /** @var class-string<T> $class */
            return $class;
        }

        return null;
    }

    /**
     * Resolve the Livewire component class backing a view, using Livewire's
     * class-namespace convention (a `livewire.create-refund` view maps to
     * `App\Livewire\CreateRefund`). A component with a custom render() pointing
     * template's signature for those.
     *
     * @return class-string<LivewireComponent>|null
     */
    private function resolveLivewireClass(string $viewName): ?string
    {
        if (! class_exists(LivewireComponent::class) || ! str_starts_with($viewName, 'livewire.')) {
            return null;
        }

        return $this->buildComponentClass(
            $this->livewireClassNamespace(),
            substr($viewName, strlen('livewire.')),
            LivewireComponent::class,
        );
    }

    /**
     * @param class-string<LivewireComponent> $class
     * @return array<string, string>
     */
    private function livewireScope(string $class): array
    {
        // Public methods are Livewire actions (wire:click), not view variables;
        // $this->method() resolves through the typed $this instead.
        $scope = $this->reflectMembers($class, LivewireComponent::class, includeMethods: false);

        $scope['this'] = '\\' . $class;
        $scope['_instance'] = '\\' . $class;
        $scope['__livewire'] = '\\' . $class;

        return $scope;
    }

    private function livewireClassNamespace(): string
    {
        try {
            $namespace = Container::getInstance()
                ->make('config')
                ->get('livewire.class_namespace');

            return is_string($namespace) ? $namespace : 'App\\Livewire';
        } catch (Throwable) {
            return 'App\\Livewire';
        }
    }

    private function applicationNamespace(): string
    {
        try {
            return Container::getInstance()
                ->make(Application::class)
                ->getNamespace();
        } catch (Throwable) {
            return 'App\\';
        }
    }

    /**
     * The public members a component exposes to its view: public properties,
     * and (for Blade components) public zero-argument methods as closures.
     * Members declared on the framework base class are skipped.
     *
     * @param class-string $class
     * @param class-string $frameworkBase
     * @return array<string, string>
     */
    private function reflectMembers(string $class, string $frameworkBase = Component::class, bool $includeMethods = true): array
    {
        // The class is a verified class-string (buildComponentClass checked
        // class_exists), so ReflectionClass cannot fail to construct it.
        $reflectionClass = new ReflectionClass($class);

        $members = [];

        foreach ($reflectionClass->getProperties(ReflectionProperty::IS_PUBLIC) as $reflectionProperty) {
            if ($reflectionProperty->isStatic()) {
                continue;
            }

            if ($this->isFrameworkMember($reflectionProperty->getDeclaringClass()->getName(), $frameworkBase)) {
                continue;
            }

            $members[$reflectionProperty->getName()] = $this->typeToString($reflectionProperty->getType());
        }

        if (! $includeMethods) {
            return $members;
        }

        foreach ($reflectionClass->getMethods(ReflectionMethod::IS_PUBLIC) as $reflectionMethod) {
            if ($reflectionMethod->isStatic()) {
                continue;
            }

            if ($reflectionMethod->isAbstract()) {
                continue;
            }

            if ($reflectionMethod->getNumberOfRequiredParameters() > 0) {
                continue;
            }

            $name = $reflectionMethod->getName();
            if (str_starts_with($name, '__')) {
                continue;
            }

            if (in_array($name, self::IGNORED_METHODS, true)) {
                continue;
            }

            if ($this->isFrameworkMember($reflectionMethod->getDeclaringClass()->getName(), $frameworkBase)) {
                continue;
            }

            $members[$name] = '\Closure(): ' . $this->typeToString($reflectionMethod->getReturnType());
        }

        return $members;
    }

    /**
     * A member declared on the framework base class (or an ancestor of it) is
     * framework plumbing, not something the component exposes as a view variable.
     *
     * @param class-string $frameworkBase
     */
    private function isFrameworkMember(string $declaringClass, string $frameworkBase): bool
    {
        return $declaringClass === $frameworkBase || is_subclass_of($frameworkBase, $declaringClass);
    }

    private function typeToString(?ReflectionType $reflectionType): string
    {
        if ($reflectionType instanceof ReflectionNamedType) {
            $name = $reflectionType->getName();
            if ($name === 'mixed' || $name === 'null') {
                return $name;
            }

            if (in_array($name, ['self', 'static', 'parent'], true)) {
                return 'object';
            }

            $typeString = $reflectionType->isBuiltin() ? $name : '\\' . ltrim($name, '\\');

            return $reflectionType->allowsNull() ? '?' . $typeString : $typeString;
        }

        if ($reflectionType instanceof ReflectionUnionType) {
            return implode('|', array_map(
                fn (ReflectionType $reflectionType): string => ltrim($this->typeToString($reflectionType), '?'),
                $reflectionType->getTypes(),
            ));
        }

        if ($reflectionType instanceof ReflectionIntersectionType) {
            return implode('&', array_map(
                fn (ReflectionType $reflectionType): string => $this->typeToString($reflectionType),
                $reflectionType->getTypes(),
            ));
        }

        return 'mixed';
    }

    /**
     * Infer a general type from a prop's default expression. Defaults are
     * literals in the common case; anything else (a function call, a constant)
     * is left as mixed, exactly as an untyped parameter would be.
     */
    private function typeFromDefaultExpression(?string $defaultExpression): string
    {
        if ($defaultExpression === null) {
            return 'mixed';
        }

        $expression = trim($defaultExpression);

        return match (true) {
            preg_match('/^([\'"]).*\1$/s', $expression) === 1 => 'string',
            preg_match('/^-?\d+$/', $expression) === 1 => 'int',
            preg_match('/^-?\d*\.\d+$/', $expression) === 1 => 'float',
            preg_match('/^(true|false)$/i', $expression) === 1 => 'bool',
            preg_match('/^\[.*\]$/s', $expression) === 1, str_starts_with($expression, 'array(') => 'array',
            default => 'mixed',
        };
    }
}
