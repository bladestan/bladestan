<?php

declare(strict_types=1);

namespace Bladestan\Compiler;

use Illuminate\Container\Container;
use Livewire\Component as LivewireComponent;
use Throwable;

/**
 * Resolves a Livewire component name (`cart.preview`) to the class backing it,
 * by asking Livewire itself.
 *
 * `livewire.class_namespace` is only Livewire's auto-discovery root, not its
 * mapping. A component can also be registered explicitly with
 * `Livewire::component()` (how packages ship components, and the only way to
 * register one living outside the configured root), be produced by a
 * `resolveMissingComponent()` hook, or follow the `Name\Index` convention.
 * Rebuilding the class name from the configured namespace sees none of that, so
 * it names a class that does not exist and every property read on the instance
 * becomes a second error. Livewire keeps the real map behind a container
 * binding, which is what this asks.
 *
 * @see \Bladestan\Tests\Compiler\LivewireComponentClassResolverTest
 */
final class LivewireComponentClassResolver
{
    /**
     * The container bindings holding Livewire's name-to-class map, and the
     * method on each that answers for one name. Livewire 3 keeps the map in a
     * registry mechanism and Livewire 4 in the finder; both are asked through
     * public API, and a binding that is absent is simply skipped, so either
     * version works and neither is required.
     *
     * Livewire 4's factory resolves a wider set of names than its finder, but
     * only by compiling single-file components on the spot: that writes to
     * Livewire's cache and yields a generated class PHPStan cannot autoload, so
     * the finder is the right question to ask during analysis.
     *
     * @var array<string, string>
     */
    private const RESOLVER_BINDINGS = [
        'Livewire\\Mechanisms\\ComponentRegistry' => 'getClass',
        'livewire.finder' => 'resolveClassComponentClassName',
    ];

    /**
     * The class backing a Livewire component name, or null when Livewire cannot
     * name one: it is not installed, not booted, or does not know the component.
     * The caller decides what to do with that, since a component Livewire cannot
     * resolve is exactly the one worth reporting.
     *
     * @return class-string<LivewireComponent>|null
     */
    public function resolve(string $componentName): ?string
    {
        foreach (self::RESOLVER_BINDINGS as $abstract => $method) {
            // Livewire returns the class name as it was registered, which for a
            // discovered component carries a leading backslash. Compiled PHP and
            // reflection both want it bare.
            $class = ltrim($this->ask($abstract, $method, $componentName) ?? '', '\\');

            if ($class === '') {
                continue;
            }

            if (class_exists($class) && is_subclass_of($class, LivewireComponent::class)) {
                /** @var class-string<LivewireComponent> $class */
                return $class;
            }
        }

        return null;
    }

    private function ask(string $abstract, string $method, string $componentName): ?string
    {
        try {
            $container = Container::getInstance();

            // Only an already-bound resolver is asked. Building a fresh one
            // would hold none of the application's registrations and answer as
            // if every component were undeclared.
            if (! $container->bound($abstract)) {
                return null;
            }

            $resolver = $container->make($abstract);
            if (! is_object($resolver) || ! method_exists($resolver, $method)) {
                return null;
            }

            $class = $resolver->{$method}($componentName);
        } catch (Throwable) {
            // Livewire 3 throws ComponentNotFoundException for a name it cannot
            // resolve where Livewire 4 returns null, and a container without a
            // booted application throws on the lookup itself.
            return null;
        }

        return is_string($class) ? $class : null;
    }
}
