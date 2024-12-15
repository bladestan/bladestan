<?php

namespace Bladestan\Compiler;

use Exception;
use Illuminate\View\AnonymousComponent;

class LivewireTagCompiler extends ComponentTagCompiler
{
    public function compile(string $value): string
    {
        return $this->compileLivewireSelfClosingTags($value);
    }

    protected function compileLivewireSelfClosingTags(string $value): string
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
                ->mapWithKeys(function ($value, $key) {
                    // Skip snake_cased attributes.
                    if (is_int($key) || str($key)->contains('_')) {
                        return [
                            strval($key) => $value,
                        ];
                    }

                    return [
                        (string) str($key)
                            ->camel() => $value,
                    ];
                })->toArray();

            // Convert all snake_cased attributes to camelCase, and merge with
            // existing attributes so both snake and camel are available.
            $attributes = collect($attributes)
                ->mapWithKeys(function ($value, $key) {
                    // Skip snake_cased attributes
                    if (! str($key)->contains('_')) {
                        return [
                            strval($key) => false,
                        ];
                    }

                    return [
                        (string) str($key)
                            ->camel() => $value,
                    ];
                })->filter()
                ->merge($attributes)
                ->toArray();

            $component = $matches[1];

            if ($component === 'styles') {
                return '@livewireStyles';
            }

            if ($component === 'scripts') {
                return '@livewireScripts';
            }

            return $this->componentString(AnonymousComponent::class, $attributes);
        }, $value) ?? throw new Exception('preg_replace_callback error');
    }

    /**
     * @param array<string, mixed> $attributes
     */
    protected function componentString(string $component, array $attributes): string
    {
        $attrString = $this->attributesToString($attributes, $escapeBound = false);
        return sprintf('<?php echo %s::resolve([%s])->render(); ?>', $component, $attrString);
    }
}
