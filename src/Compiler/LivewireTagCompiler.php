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
     * Create a new component tag compiler.
     */
    public function __construct(
        protected ArrayStringToArrayConverter $arrayStringToArrayConverter
    ) {
    }

    public function replace(string $rawPhpContent): string
    {
        return preg_replace_callback(self::LIVEWIRE_REGEX, function (array $match): string {
            $block = $match[1];
            if (! preg_match(self::LIVEWIRE_ARGS_REGEX, $block, $match)) {
                throw new ShouldNotHappenException('Could not extract Livewire arguments from block: ' . $block);
            }

            $attributes = $this->arrayStringToArrayConverter->convert($match[2]);
            return $this->componentString($match[1], $attributes);
        }, $rawPhpContent) ?? throw new ShouldNotHappenException('preg_replace_callback error');
    }

    /**
     * @param array<string> $attributes
     */
    private function componentString(string $component, array $attributes): string
    {
        $class = $this->getComponentClass($component);

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
