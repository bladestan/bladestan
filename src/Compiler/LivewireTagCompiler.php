<?php

namespace Bladestan\Compiler;

use Bladestan\Exception\ShouldNotHappenException;
use Bladestan\PhpParser\ArrayStringToArrayConverter;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionNamedType;

class LivewireTagCompiler
{
    /**
     * @see https://regex101.com/r/4c923z/1
     * @var string
     */
    private const LIVEWIRE_REGEX = '/\s*\$__split = function \(\$name, \$params = \[\]\) {
\s*    return \[\$name, \$params\];
\s*};
\s*\[\$__name, \$__params\] = \$__split\(\'([^\']*?)\', (.+?)\);
\s*\$__html = app\(\'livewire\'\)->mount\(\$__name, \$__params, .+?, \$__slots \?\? \[\], get_defined_vars\(\)\);
\s*echo \$__html;
\s*unset\(\$__html\);
\s*unset\(\$__name\);
\s*unset\(\$__params\);
\s*unset\(\$__split\);
\s*if \(isset\(\$__slots\)\) {
\s*    unset\(\$__slots\);
\s*}/s';

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
                    ->map(function (mixed $value, string $attribute): string {
                        return "{$attribute}: {$value}";
                    })
                    ->implode(', ');

                $mount = " \$component->mount({$attrString});";
            }
        }

        $properties = collect($attributes)
            ->map(function (mixed $value, string $attribute): string {
                return "\$component->{$attribute} = {$value}";
            })
            ->implode('; ');
        if ($properties) {
            $properties = " {$properties};";
        }

        return "\$component = new {$class}();{$mount}{$properties}";
    }

    private function getComponentClass(string $view): string
    {
        // Convert the view string to PascalCase for the class name
        $className = collect(explode('.', $view))
            ->map(function (string $part): string {
                return Str::studly($part);
            })
            ->implode('\\');

        return "App\\View\\Components\\{$className}";
    }
}
