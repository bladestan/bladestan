<?php

namespace Bladestan\Compiler;

use Bladestan\Exception\ShouldNotHappenException;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\View\Compilers\BladeCompiler;
use ReflectionClass;
use ReflectionNamedType;

class LivewireTagCompiler
{
    /**
     * Create a new component tag compiler.
     */
    public function __construct(
        protected BladeCompiler $bladeCompiler
    ) {
    }

    public function compile(string $value): string
    {
        $pattern = "/
            <
                \s*
                livewire\:([\w\-\:\.]*)
                \s*
                (?<attributes>
                    (?:
                        \s+
                        [\w\-:.@]+
                        (
                            =
                            (?:
                                \\\"[^\\\"]*\\\"
                                |
                                \'[^\']*\'
                                |
                                [^\'\\\"=<>]+
                            )
                        )?
                    )*
                    \s*
                )
            \/?>
        /x";

        return preg_replace_callback($pattern, function (array $matches): string {
            $attributes = $this->getAttributesFromAttributeString($matches['attributes']);

            // Convert all kebab-cased to camelCase.
            $attributes = collect($attributes)
                ->mapWithKeys(function (mixed $value, string|int $key): array {
                    // Skip snake_cased attributes.
                    if (is_int($key) || Str::of($key)->contains('_')) {
                        return [
                            strval($key) => $value,
                        ];
                    }

                    return [
                        (string) Str::of($key)
                            ->camel() => $value,
                    ];
                })->toArray();

            // Convert all snake_cased attributes to camelCase, and merge with
            // existing attributes so both snake and camel are available.
            $attributes = collect($attributes)
                ->mapWithKeys(function (mixed $value, string $key): array {
                    // Skip snake_cased attributes
                    if (! Str::of($key)->contains('_')) {
                        return [
                            strval($key) => false,
                        ];
                    }

                    return [
                        (string) Str::of($key)
                            ->camel() => $value,
                    ];
                })->filter()
                ->merge($attributes)
                ->toArray();

            // Filter out attribute-only values
            $attributes = array_filter($attributes, function (string $key): bool {
                return preg_match('#^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$#s', $key) === 1;
            }, ARRAY_FILTER_USE_KEY);

            $component = $matches[1];

            if ($component === 'styles') {
                return '@livewireStyles';
            }

            if ($component === 'scripts') {
                return '@livewireScripts';
            }

            return $this->componentString($component, $attributes);
        }, $value) ?? throw new ShouldNotHappenException('preg_replace_callback error');
    }

    /**
     * Strip any quotes from the given string.
     */
    public function stripQuotes(string $value): string
    {
        return Str::startsWith($value, ['"', "'"])
                    ? substr($value, 1, -1)
                    : $value;
    }

    /**
     * @param array<string, mixed> $attributes
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

                // Resolve any addtional required arguments

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
                        assert(is_string($value));
                        return "{$attribute}: {$value}";
                    })
                    ->implode(', ');

                $mount = " \$component->mount({$attrString});";
            }
        }

        $properties = collect($attributes)
            ->map(function (mixed $value, string $attribute): string {
                assert(is_string($value));
                return "\$component->{$attribute} = {$value}";
            })
            ->implode('; ');
        if ($properties) {
            $properties = " {$properties};";
        }

        return "<?php \$component = new {$class}();{$mount}{$properties} ?>";
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

    /**
     * Get an array of attributes from the given attribute string.
     *
     * @return array<array-key, mixed>
     */
    private function getAttributesFromAttributeString(string $attributeString): array
    {
        $attributeString = $this->parseShortAttributeSyntax($attributeString);
        $attributeString = $this->parseAttributeBag($attributeString);
        $attributeString = $this->parseComponentTagClassStatements($attributeString);
        $attributeString = $this->parseComponentTagStyleStatements($attributeString);
        $attributeString = $this->parseBindAttributes($attributeString);

        $pattern = '/
            (?<attribute>[\w\-:.@]+)
            (
                =
                (?<value>
                    (
                        \"[^\"]+\"
                        |
                        \\\'[^\\\']+\\\'
                        |
                        [^\s>]+
                    )
                )
            )?
        /x';

        if (! preg_match_all($pattern, $attributeString, $matches, PREG_SET_ORDER)) {
            return [];
        }

        /** @var list<array<array-key, string>> $matches */
        return collect($matches)->mapWithKeys(function (array $match): array {
            $attribute = $match['attribute'];
            $value = $match['value'] ?? null;

            if ($value === null) {
                $value = 'true';

                $attribute = Str::start($attribute, 'bind:');
            }

            $value = $this->stripQuotes($value);

            if (str_starts_with($attribute, 'bind:')) {
                $attribute = Str::after($attribute, 'bind:');
            } else {
                $value = "'" . $this->compileAttributeEchos($value) . "'";
            }

            if (str_starts_with($attribute, '::')) {
                $attribute = substr($attribute, 1);
            }

            return [
                $attribute => $value,
            ];
        })->toArray();
    }

    /**
     * Parses a short attribute syntax like :$foo into a fully-qualified syntax like :foo="$foo".
     */
    private function parseShortAttributeSyntax(string $value): string
    {
        $pattern = "/\s\:\\\$(\w+)/x";

        return preg_replace_callback(
            $pattern,
            fn (array $matches): string => " :{$matches[1]}=\"\${$matches[1]}\"",
            $value
        ) ?? throw new ShouldNotHappenException('preg_replace_callback error');
    }

    /**
     * Parse the attribute bag in a given attribute string into its fully-qualified syntax.
     */
    private function parseAttributeBag(string $attributeString): string
    {
        $pattern = "/
            (?:^|\s+)                                        # start of the string or whitespace between attributes
            \{\{\s*(\\\$attributes(?:[^}]+?(?<!\s))?)\s*\}\} # exact match of attributes variable being echoed
        /x";

        return preg_replace(
            $pattern,
            ' :attributes="$1"',
            $attributeString
        ) ?? throw new ShouldNotHappenException('preg_replace error');
    }

    /**
     * Parse @class statements in a given attribute string into their fully-qualified syntax.
     */
    private function parseComponentTagClassStatements(string $attributeString): string
    {
        return preg_replace_callback(
            '/@(class)(\( ( (?>[^()]+) | (?2) )* \))/x',
            function (array $match): string {
                if ($match[1] === 'class') {
                    $match[2] = str_replace('"', "'", $match[2]);

                    return ':class="' . Arr::class . "::toCssClasses{$match[2]}\"";
                }

                return $match[0];
            },
            $attributeString
        ) ?? throw new ShouldNotHappenException('preg_replace_callback error');
    }

    /**
     * Parse @style statements in a given attribute string into their fully-qualified syntax.
     */
    private function parseComponentTagStyleStatements(string $attributeString): string
    {
        return preg_replace_callback(
            '/@(style)(\( ( (?>[^()]+) | (?2) )* \))/x',
            function (array $match): string {
                if ($match[1] === 'style') {
                    $match[2] = str_replace('"', "'", $match[2]);

                    return ':style="' . Arr::class . "::toCssStyles{$match[2]}\"";
                }

                return $match[0];
            },
            $attributeString
        ) ?? throw new ShouldNotHappenException('preg_replace_callback error');
    }

    /**
     * Parse the "bind" attributes in a given attribute string into their fully-qualified syntax.
     */
    private function parseBindAttributes(string $attributeString): string
    {
        $pattern = "/
            (?:^|\s+)     # start of the string or whitespace between attributes
            :(?!:)        # attribute needs to start with a single colon
            ([\w\-:.@]+)  # match the actual attribute name
            =             # only match attributes that have a value
        /xm";

        return preg_replace(
            $pattern,
            ' bind:$1=',
            $attributeString
        ) ?? throw new ShouldNotHappenException('preg_replace error');
    }

    /**
     * Compile any Blade echo statements that are present in the attribute string.
     *
     * These echo statements need to be converted to string concatenation statements.
     */
    private function compileAttributeEchos(string $attributeString): string
    {
        $value = $this->bladeCompiler->compileEchos($attributeString);

        $value = $this->escapeSingleQuotesOutsideOfPhpBlocks($value);

        $value = str_replace('<?php echo ', "'.", $value);

        return str_replace('; ?>', ".'", $value);
    }

    /**
     * Escape the single quotes in the given string that are outside of PHP blocks.
     */
    private function escapeSingleQuotesOutsideOfPhpBlocks(string $value): string
    {
        return collect(token_get_all($value))
            ->map(function ($token): string {
                if (! is_array($token)) {
                    return $token;
                }

                return $token[0] === T_INLINE_HTML
                            ? str_replace("'", "\\'", $token[1])
                            : $token[1];
            })->implode('');
    }
}
