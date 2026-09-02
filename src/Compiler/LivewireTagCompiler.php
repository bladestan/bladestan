<?php

namespace Bladestan\Compiler;

use Bladestan\Exception\ShouldNotHappenException;
use Bladestan\PhpParser\ArrayStringToArrayConverter;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionNamedType;

class LivewireTagCompiler
{
    /**
     * @see https://regex101.com/r/T2rrSI/1
     * @var string Regex to find each Livewire block
     */
    private const LIVEWIRE_REGEX = '/\s*\$__split = function \(\$name, \$params = \[\]\) {
\s*    return \[\$name, \$params\];
\s*};
(.+?)
\s*unset\(\$__split\);
(?:\s*if \(isset\(\$__slots\)\) {
\s*    unset\(\$__slots\);
\s*})?/s';

    /**
     * @see https://regex101.com/r/twpxFN/1
     * @var string Regex to extract the component name and parameters from a block
     */
    private const LIVEWIRE_ARGS_REGEX = '/\$__split\(\'([^\']*?)\', (.+?)\);$/sm';

    /**
     * Livewire classes named by the tags replaced in the last replace() call,
     * as a set keyed by class name. Their mount() signature is reflected into
     * the output below, so whoever caches that output has to recompile when the
     * signature changes. A class that does not exist is recorded too: creating
     * it changes the output just as much as editing it.
     *
     * @var array<string, true>
     */
    private array $referencedClasses = [];

    private readonly LivewireComponentClassResolver $livewireComponentClassResolver;

    /**
     * Create a new component tag compiler.
     */
    public function __construct(
        protected ArrayStringToArrayConverter $arrayStringToArrayConverter
    ) {
        $this->livewireComponentClassResolver = new LivewireComponentClassResolver();
    }

    /**
     * The Livewire classes whose signature shaped the last replace() call.
     *
     * @return list<string>
     */
    public function getReferencedClasses(): array
    {
        return array_keys($this->referencedClasses);
    }

    public function replace(string $rawPhpContent): string
    {
        $this->referencedClasses = [];

        return preg_replace_callback(self::LIVEWIRE_REGEX, function (array $match): string {
            $block = $match[1];
            if (! preg_match(self::LIVEWIRE_ARGS_REGEX, $block, $match)) {
                // Dynamic component name (e.g. @livewire($tile['view'], [...])): the args
                // regex only matches literal names, and a dynamic component cannot be
                // statically resolved anyway. Skip the block instead of aborting the
                // analysis of the whole file.
                return '';
            }

            $attributes = $this->arrayStringToArrayConverter->convert($match[2]);
            $attributes = collect($attributes)
                ->mapWithKeys(fn (string $value, string $key): array => [
                    Str::camel($key) => $value,
                ])
                ->all();

            return $this->componentString($match[1], $attributes);
        }, $rawPhpContent) ?? throw new ShouldNotHappenException('preg_replace_callback error');
    }

    /**
     * @param array<string> $attributes
     */
    private function componentString(string $component, array $attributes): string
    {
        $class = $this->getComponentClass($component);
        $this->referencedClasses[ltrim($class, '\\')] = true;

        $mount = '';
        if (class_exists($class) && method_exists($class, 'mount')) {
            $mountArgs = [];

            $parameters = (new ReflectionClass($class))->getMethod('mount')
                ->getParameters();
            foreach ($parameters as $parameter) {
                $paramName = $parameter->getName();
                if (isset($attributes[$paramName])) {
                    $mountArgs[$paramName] = $attributes[$paramName];
                    unset($attributes[$paramName]);
                    continue;
                }

                // Resolve any additional required arguments

                $paramType = $parameter->getType();
                if (! $paramType instanceof ReflectionNamedType) {
                    continue;
                }

                if ($paramType->allowsNull()) {
                    $mountArgs[$paramName] = 'null';
                    continue;
                }

                $paramClass = $paramType->getName();
                if (class_exists($paramClass) || interface_exists($paramClass)) {
                    $mountArgs[$paramName] = "resolve({$paramClass}::class)";
                    continue;
                }
            }

            if ($mountArgs !== []) {
                $attrString = collect($mountArgs)
                    ->map(fn (mixed $value, string $attribute): string => "{$attribute}: {$value}")
                    ->implode(', ');

                $mount = " \$component->mount({$attrString});";
            }
        }

        $properties = collect($attributes)
            ->map(fn (mixed $value, string $attribute): string => "\$component->{$attribute} = {$value}")
            ->implode('; ');
        if ($properties) {
            $properties = " {$properties};";
        }

        return "\$component = new {$class}();{$mount}{$properties}";
    }

    private function getComponentClass(string $view): string
    {
        $resolvedClass = $this->livewireComponentClassResolver->resolve($view);
        if ($resolvedClass !== null) {
            return $resolvedClass;
        }

        // Livewire knows no class for this component, which is what a genuinely
        // missing component looks like. Fall back to the discovery convention so
        // the class.notFound that follows names what the author meant.
        try {
            $namespace = Config::string('livewire.class_namespace');
        } catch (InvalidArgumentException) {
            $namespace = 'App\\Livewire';
        }

        // Convert the view string to PascalCase for the class name
        $className = collect(explode('.', $view))
            ->map(fn (string $part): string => Str::studly($part))
            ->implode('\\');

        return "{$namespace}\\{$className}";
    }
}
