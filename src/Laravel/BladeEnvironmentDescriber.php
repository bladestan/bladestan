<?php

declare(strict_types=1);

namespace Bladestan\Laravel;

use Closure;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\FileViewFinder;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionProperty;
use Throwable;

/**
 * Stable text descriptions of the application's view configuration, for
 * telling whether it changed between two runs.
 *
 * A callable (a directive, a precompiler) is described by the source text of
 * its definition, so editing a directive's body counts as a change, but editing
 * a method that body calls does not. That takes runtime reflection of the
 * application's own objects, which only exist in the running application.
 *
 * Only usable once the application has booted.
 *
 * @see \Bladestan\Tests\Laravel\BladeEnvironmentDescriberTest
 */
final class BladeEnvironmentDescriber
{
    /**
     * Blade compiler properties that hold configuration rather than per-compile
     * state. Read by name since most are protected; one that a Laravel version
     * does not have is skipped.
     *
     * @var list<string>
     */
    private const COMPILER_PROPERTIES = [
        'extensions',
        'customDirectives',
        'conditions',
        'prepareStringsForCompilationUsing',
        'precompilers',
        'echoHandlers',
        'rawTags',
        'contentTags',
        'escapedTags',
        'echoFormat',
        'anonymousComponentPaths',
        'anonymousComponentNamespaces',
        'classComponentAliases',
        'classComponentNamespaces',
        'compilesComponentTags',
    ];

    /**
     * Directives, conditions, precompilers, extensions, echo handlers, echo
     * tags, and component aliases, namespaces and paths.
     */
    public function describeCompiler(BladeCompiler $bladeCompiler): string
    {
        $configuration = [];
        foreach (self::COMPILER_PROPERTIES as $propertyName) {
            if (! property_exists($bladeCompiler, $propertyName)) {
                continue;
            }

            $configuration[$propertyName] = $this->describeValue(
                (new ReflectionProperty($bladeCompiler, $propertyName))->getValue($bladeCompiler)
            );
        }

        return serialize($configuration);
    }

    /**
     * The view finder's paths, namespace hints and extensions.
     */
    public function describeFinder(): string
    {
        $finder = resolve(ViewFactory::class)->getFinder();
        if (! $finder instanceof FileViewFinder) {
            return $finder::class;
        }

        return serialize([$finder->getPaths(), $finder->getHints(), $finder->getExtensions()]);
    }

    /**
     * Scalars as they are, arrays element by element, callables by the source
     * of their definition, and any other object by its class.
     */
    private function describeValue(mixed $value): string
    {
        $reflection = is_callable($value) ? $this->reflectCallable($value) : null;
        if ($reflection instanceof ReflectionFunctionAbstract) {
            return $this->describeFunction($reflection);
        }

        if (is_array($value)) {
            $described = [];
            foreach ($value as $key => $item) {
                $described[$key] = $this->describeValue($item);
            }

            return serialize($described);
        }

        if (is_object($value)) {
            return $value::class;
        }

        return serialize($value);
    }

    private function reflectCallable(callable $callable): ?ReflectionFunctionAbstract
    {
        try {
            if ($callable instanceof Closure) {
                return new ReflectionFunction($callable);
            }

            if (is_string($callable)) {
                return str_contains($callable, '::')
                    ? new ReflectionMethod($callable)
                    : new ReflectionFunction($callable);
            }

            if (is_array($callable)) {
                return new ReflectionMethod($callable[0], $callable[1]);
            }

            if (is_object($callable)) {
                return new ReflectionMethod($callable, '__invoke');
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    private function describeFunction(ReflectionFunctionAbstract $reflectionFunctionAbstract): string
    {
        $fileName = $reflectionFunctionAbstract->getFileName();
        $startLine = $reflectionFunctionAbstract->getStartLine();
        $endLine = $reflectionFunctionAbstract->getEndLine();
        if ($fileName === false || $startLine === false || $endLine === false) {
            return 'internal ' . $reflectionFunctionAbstract->getName();
        }

        $lines = @file($fileName);
        if ($lines === false) {
            return $fileName . ':' . $startLine;
        }

        return implode('', array_slice($lines, $startLine - 1, $endLine - $startLine + 1));
    }
}
