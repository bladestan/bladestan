<?php

declare(strict_types=1);

namespace Bladestan\Compiler;

use Bladestan\PhpParser\ArrayStringToArrayConverter;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\ComponentAttributeBag;
use Illuminate\View\ComponentSlot;
use PHPStan\Type\ArrayType;
use PHPStan\Type\BooleanType;
use PHPStan\Type\FloatType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\MixedType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;

/**
 * Determines the extra variables in scope inside a component template's body,
 * so a standalone-compiled component is analyzed with the same scope Blade
 * gives it at runtime.
 *
 * A template signature declares what a caller must pass; it says nothing about
 * the variables Blade injects into a component body. Those are `$attributes`,
 * `$slot`, `$componentName`, and every `@props` variable (present via its
 * default or the attributes bag). Without them, a component body that reads
 * `{{ $slot }}` or `{{ $attributes->merge(...) }}` reports undefined variables
 * that no signature could fix.
 *
 * A template is treated as a component when it declares `@props` or its view
 * name is a component view (the `components.` convention or a registered
 * component namespace). Reflected backing-class members are not injected here;
 * they are contributed by the template's signature.
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

    public function __construct(
        private readonly BladeCompiler $bladeCompiler,
        private readonly ArrayStringToArrayConverter $arrayStringToArrayConverter,
    ) {
    }

    /**
     * The variables Blade adds to a component body, or an empty array when the
     * template is not a component.
     *
     * @return array<string, Type>
     */
    public function resolve(string $viewName, string $bladeContent): array
    {
        $props = $this->extractProps($bladeContent);
        if ($props === null && ! $this->isComponentView($viewName)) {
            return [];
        }

        $scope = [
            'slot' => new ObjectType(ComponentSlot::class),
            'componentName' => new StringType(),
        ];

        // A compiled `@props` block already defines `$attributes` (it opens with
        // `$attributes ??= new ComponentAttributeBag()`), so only declare it for
        // component bodies without `@props`.
        if ($props === null) {
            $scope['attributes'] = new ObjectType(ComponentAttributeBag::class);

            return $scope;
        }

        foreach ($props as $name => $defaultExpression) {
            $scope[$name] = $this->typeFromDefaultExpression($defaultExpression);
        }

        return $scope;
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
     * Infer a general type from a prop's default expression. Defaults are
     * literals in the common case; anything else (a function call, a constant)
     * is left as mixed, exactly as an untyped parameter would be.
     */
    private function typeFromDefaultExpression(?string $defaultExpression): Type
    {
        if ($defaultExpression === null) {
            return new MixedType();
        }

        $expression = trim($defaultExpression);

        return match (true) {
            preg_match('/^([\'"]).*\1$/s', $expression) === 1 => new StringType(),
            preg_match('/^-?\d+$/', $expression) === 1 => new IntegerType(),
            preg_match('/^-?\d*\.\d+$/', $expression) === 1 => new FloatType(),
            preg_match('/^(true|false)$/i', $expression) === 1 => new BooleanType(),
            preg_match('/^\[.*\]$/s', $expression) === 1, str_starts_with($expression, 'array(') => new ArrayType(new MixedType(), new MixedType()),
            default => new MixedType(),
        };
    }
}
