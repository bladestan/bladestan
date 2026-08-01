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
     * Component methods Blade never exposes as view variables. Framework methods
     * declared on Component itself are excluded by their declaring class; these
     * are the ones a component commonly overrides, so they need naming.
     *
     * @var list<string>
     */
    private const IGNORED_METHODS = ['render', 'resolveView', 'shouldRender', 'view', 'data', 'withName', 'withAttributes'];

    private readonly BladeInertRegionMasker $bladeInertRegionMasker;

    private readonly PropsDirectiveExtractor $propsDirectiveExtractor;

    private readonly LivewireComponentClassResolver $livewireComponentClassResolver;

    public function __construct(
        private readonly BladeCompiler $bladeCompiler,
        private readonly ArrayStringToArrayConverter $arrayStringToArrayConverter,
    ) {
        $this->bladeInertRegionMasker = new BladeInertRegionMasker();
        $this->propsDirectiveExtractor = new PropsDirectiveExtractor();
        $this->livewireComponentClassResolver = new LivewireComponentClassResolver();
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

        // Props are the highest-priority body variables: an explicit @props
        // entry wins over a reflected class member of the same name.
        return [...$scope, ...$this->typesForProps($props)];
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

        return $this->typesForProps($props);
    }

    /**
     * Map each `@props` entry to the type of its default value (or `mixed` when
     * it has no default).
     *
     * @param array<string, string|null> $props prop name => default expression
     * @return array<string, string> prop name => PHPDoc type string
     */
    private function typesForProps(array $props): array
    {
        $types = [];
        foreach ($props as $name => $defaultExpression) {
            $types[$name] = $this->typeFromDefaultExpression($defaultExpression);
        }

        return $types;
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
        // A @props inside a comment, @verbatim, or @php block is inert to
        // Blade, so mask those regions before scanning for the real
        // declaration. @php blocks are masked too so a @props written inside a
        // PHP string literal cannot be read as the component's contract.
        $bladeContent = $this->bladeInertRegionMasker->mask($bladeContent, maskPhpBlocks: true);

        $arrayLiteral = $this->propsDirectiveExtractor->extractArrayLiteral($bladeContent);
        if ($arrayLiteral === null) {
            return null;
        }

        $converted = $this->arrayStringToArrayConverter->convert($arrayLiteral);

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

        foreach ($this->componentDirectoriesByPrefix() as $prefix => $directory) {
            if ($this->componentNamesUnderNamespace($viewName, $prefix, $directory) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every registered component prefix, mapped to the anonymous-component
     * directory rooted under it (an empty string when the prefix registers only
     * a class namespace).
     *
     * @return array<string, string>
     */
    private function componentDirectoriesByPrefix(): array
    {
        $directories = [];

        foreach (array_keys($this->bladeCompiler->getClassComponentNamespaces()) as $prefix) {
            if (is_string($prefix)) {
                $directories[$prefix] = '';
            }
        }

        foreach ($this->bladeCompiler->getAnonymousComponentNamespaces() as $prefix => $directory) {
            if (is_string($prefix) && is_string($directory)) {
                $directories[$prefix] = $directory;
            }
        }

        return $directories;
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
        $directories = $this->componentDirectoriesByPrefix();

        foreach ($this->bladeCompiler->getClassComponentNamespaces() as $prefix => $namespace) {
            if (! is_string($prefix) || ! is_string($namespace)) {
                continue;
            }

            foreach ($this->componentNamesUnderNamespace($viewName, $prefix, $directories[$prefix] ?? '') as $componentName) {
                $class = $this->buildComponentClass($namespace, $componentName);
                if ($class !== null) {
                    return $class;
                }
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

    /**
     * The component names a view name can stand for under one registered prefix,
     * most specific first, or an empty list when it stands for none.
     *
     * A view name arrives in one of three shapes: addressed through the view
     * namespace and the anonymous-component directory both
     * (`webshop::components.member.savings`), through the namespace alone
     * (`webshop::member.savings`), or through the directory alone
     * (`components.member.savings`). The first shape is what every template
     * under a registered view namespace takes, and it is the one that needs both
     * parts removed: dropping only the namespace prefix leaves the directory to
     * double into the class name (`…\Components\Components\Member\Savings`).
     *
     * @return list<string>
     */
    private function componentNamesUnderNamespace(string $viewName, string $prefix, string $directory): array
    {
        // A directory registered as `webshop::components` is rooted at the view
        // namespace, so match it against the remainder, which is already bare.
        $directoryPrefix = str_replace('/', '.', trim(Str::afterLast($directory, '::'), '/'));

        if (! str_starts_with($viewName, $prefix . '::')) {
            return $directoryPrefix !== '' && str_starts_with($viewName, $directoryPrefix . '.')
                ? [substr($viewName, strlen($directoryPrefix) + 1)]
                : [];
        }

        $rest = substr($viewName, strlen($prefix) + 2);
        if ($directoryPrefix !== '' && str_starts_with($rest, $directoryPrefix . '.')) {
            return [substr($rest, strlen($directoryPrefix) + 1), $rest];
        }

        return [$rest];
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
     * Resolve the Livewire component class backing a view, by the convention
     * that a `livewire.create-refund` view is the view of the `create-refund`
     * component. Livewire itself answers what class that name stands for, since
     * the configured class namespace is only its discovery root; the namespace
     * is the fallback for a name Livewire does not know.
     *
     * @return class-string<LivewireComponent>|null
     */
    private function resolveLivewireClass(string $viewName): ?string
    {
        if (! class_exists(LivewireComponent::class) || ! str_starts_with($viewName, 'livewire.')) {
            return null;
        }

        $componentName = substr($viewName, strlen('livewire.'));

        return $this->livewireComponentClassResolver->resolve($componentName)
            ?? $this->buildComponentClass(
                $this->livewireClassNamespace(),
                $componentName,
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
