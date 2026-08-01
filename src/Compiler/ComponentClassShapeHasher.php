<?php

declare(strict_types=1);

namespace Bladestan\Compiler;

use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * Hashes the part of a rendered component's PHP class that ends up baked into
 * compiled template output.
 *
 * A template that renders `<x-panel />` or `<livewire:counter />` compiles to a
 * call site built from the component class's own signature: the compiler reads
 * the component constructor (and `mount()` for Livewire) and fills in a value
 * for every required parameter the tag does not pass. The class is therefore a
 * compilation input that lives outside the template's source text, so a
 * constructor change has to recompile every template that renders it, or the
 * stored output keeps calling the old signature while PHPStan checks it against
 * the new one. TemplateCompilationBootstrap records these hashes per template
 * and recompiles when one stops matching.
 *
 * Hashing never throws and never reports a class as unchanged when it could not
 * be read: an absent or unloadable class gets its own marker value, so a
 * template recompiles as soon as the class appears or becomes reflectable.
 *
 * @see \Bladestan\Tests\Compiler\ComponentClassShapeHasherTest
 */
final class ComponentClassShapeHasher
{
    /**
     * The methods a rendered component's call site is built from: a Blade
     * component's constructor and a Livewire component's mount().
     *
     * @var list<string>
     */
    private const REFLECTED_METHODS = ['__construct', 'mount'];

    private const ABSENT = 'absent';

    private const UNREFLECTABLE = 'unreflectable';

    /**
     * Hashes computed so far, keyed by class name. A layout component rendered
     * by hundreds of templates is otherwise reflected once per template. Class
     * shapes cannot change while the process runs, so caching for its lifetime
     * is safe.
     *
     * @var array<string, string>
     */
    private array $hashes = [];

    /**
     * The current shape hash of each class, keyed by class name.
     *
     * @param list<string> $classes
     * @return array<string, string>
     */
    public function hashAll(array $classes): array
    {
        $hashes = [];
        foreach ($classes as $class) {
            $hashes[$class] = $this->hash($class);
        }

        return $hashes;
    }

    public function hash(string $class): string
    {
        if (isset($this->hashes[$class])) {
            return $this->hashes[$class];
        }

        return $this->hashes[$class] = $this->computeHash($class);
    }

    private function computeHash(string $class): string
    {
        try {
            /** @throws Throwable */
            if (! class_exists($class)) {
                return self::ABSENT;
            }

            $reflectionClass = new ReflectionClass($class);

            $signatures = [];
            foreach (self::REFLECTED_METHODS as $methodName) {
                if (! $reflectionClass->hasMethod($methodName)) {
                    continue;
                }

                $signatures[$methodName] = $this->describeParameters($reflectionClass->getMethod($methodName));
            }

            return hash('xxh128', serialize($signatures));
        } catch (Throwable) {
            // A class whose parent or trait is missing throws on autoload.
            // Reporting that as its own value keeps the hash honest: it differs
            // from both "absent" and any real shape, so the template recompiles
            // once the class loads cleanly again.
            return self::UNREFLECTABLE;
        }
    }

    /**
     * Everything the compilers branch on when they build a call site: each
     * parameter's name and position, whether it can be left out, its declared
     * type, and whether that type names something the container can resolve.
     *
     * @return list<array{name: string, optional: bool, type: string, nullable: bool, resolvable: bool}>
     */
    private function describeParameters(ReflectionMethod $reflectionMethod): array
    {
        $parameters = [];

        foreach ($reflectionMethod->getParameters() as $reflectionParameter) {
            $type = $reflectionParameter->getType();
            $typeName = $type instanceof ReflectionNamedType ? $type->getName() : null;

            $parameters[] = [
                'name' => $reflectionParameter->getName(),
                'optional' => $reflectionParameter->isDefaultValueAvailable(),
                'type' => $type === null ? '' : (string) $type,
                'nullable' => $type !== null && $type->allowsNull(),
                'resolvable' => $typeName !== null
                    && (class_exists($typeName) || interface_exists($typeName)),
            ];
        }

        return $parameters;
    }
}
