<?php

declare(strict_types=1);

namespace Bladestan\Console;

/**
 * Turns a type as printed by PHPStan's `dumpType` into parser-safe,
 * fully-qualified PHPDoc that can be written back into a `@bladestan-signature`.
 *
 * `dumpType` prints constructs its own docblock parser then rejects (template
 * placeholders, accessory types, integer ranges, literal unions). Writing those
 * verbatim would make the signature unparsable, so each is simplified to the
 * nearest concrete type it describes. Class names are leading-slash qualified so
 * the template needs no `use` import.
 *
 * @see \Bladestan\Tests\Console\DumpedTypeNormalizerTest
 */
final class DumpedTypeNormalizer
{
    public function normalize(string $type): string
    {
        // Template placeholders: `TModel (class \Foo, argument)` → mixed.
        $type = preg_replace('/[A-Za-z_]\w*\s*\(class [^)]*\)/', 'mixed', $type) ?? $type;
        // Accessory / conditional types: hasOffsetValue(...), hasProperty(...).
        $type = preg_replace('/has[A-Za-z]+\([^)]*\)/', '', $type) ?? $type;
        // String subtypes → string.
        $type = preg_replace(
            '/\b(?:non-empty-string|numeric-string|lowercase-string|uppercase-string|non-falsy-string|literal-string|truthy-string)\b/',
            'string',
            $type,
        ) ?? $type;
        // list<X> → array<int, X>; non-empty variants lose their emptiness marker.
        $type = preg_replace('/\bnon-empty-list</', 'list<', $type) ?? $type;
        $type = preg_replace('/\blist</', 'array<int, ', $type) ?? $type;
        $type = preg_replace('/\bnon-empty-array</', 'array<', $type) ?? $type;
        // Integer ranges → int.
        $type = preg_replace('/\bint<[^>]*>/', 'int', $type) ?? $type;
        // String literal types → string.
        $type = preg_replace("/'(?:[^'\\\\]|\\\\.)*'/", 'string', $type) ?? $type;
        // Collapse residual intersections that are now pure string.
        $type = preg_replace('/\bstring(?:&string)+\b/', 'string', $type) ?? $type;
        // Drop grouping parentheses left around unions / intersections.
        $type = str_replace(['(', ')'], '', $type);
        // Leading-slash qualify namespaced class names so no `use` is required.
        $type = preg_replace('/(?<![\\\\A-Za-z0-9_])([A-Z]\w*(?:\\\\\\w+)+)/', '\\\\$1', $type) ?? $type;
        // Tidy unions and intersections, dropping members left empty by the
        // removals above (e.g. `non-empty-array&hasOffsetValue(...)` loses its
        // accessory half and must not keep a dangling `&`).
        $type = preg_replace('/\s*([|&])\s*/', '$1', $type) ?? $type;
        $type = preg_replace('/([|&]){2,}/', '$1', $type) ?? $type;
        $type = preg_replace('/([|&])&+/', '$1', $type) ?? $type;
        $type = preg_replace('/&+([|&])/', '$1', $type) ?? $type;
        $type = trim($type, '|&');
        $type = preg_replace('/\s+/', ' ', trim($type)) ?? trim($type);

        // Collapse duplicate union members left after simplification (e.g.
        // 'a'|'b' both became string), keeping first-seen order.
        if (str_contains($type, '|')) {
            return implode('|', array_unique(explode('|', $type)));
        }

        return $type;
    }
}
