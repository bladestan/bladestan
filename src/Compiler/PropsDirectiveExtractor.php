<?php

declare(strict_types=1);

namespace Bladestan\Compiler;

/**
 * Locates `@props([...])` directives in a template.
 *
 * This is the single place the `@props` directive is recognised. Both the
 * component scope resolver (which needs the array literal to type each prop)
 * and the result-cache meta extension (which hashes the directive text to
 * decide when a contract changed) go through here, so the two can never drift
 * to different, disagreeing patterns.
 *
 * @see \Bladestan\Tests\Compiler\PropsDirectiveExtractorTest
 */
final class PropsDirectiveExtractor
{
    /**
     * Matches a `@props([...])` directive. Group 1 is the array-literal
     * argument, including its outer brackets.
     *
     * The array's brackets are balanced with a recursive subpattern (`(?1)`),
     * so a nested-array default (`@props(['a' => ['x' => 1]])`) is captured
     * whole and a call in a default (`@props(['a' => foo(1)])`) is spanned
     * rather than being truncated at the call's first closing paren. Anything
     * inside the brackets that is not itself a bracket, including parentheses,
     * is consumed verbatim.
     *
     * The one shape this cannot see is a bracket sitting inside a string
     * literal (`@props(['a' => ']'])`); balancing that needs a full PHP parse,
     * not a scan.
     *
     * @var string
     */
    private const PROPS_REGEX = '/@props\s*\(\s*(\[(?:[^\[\]]++|(?1))*+\])\s*\)/s';

    /**
     * The array-literal argument of the first `@props` directive (its `[...]`),
     * or null when the content declares no `@props`.
     */
    public function extractArrayLiteral(string $content): ?string
    {
        if (preg_match(self::PROPS_REGEX, $content, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * Every `@props` directive in the content, each as its full matched text.
     * Used for change detection, so the exact directive text is what matters.
     *
     * @return list<string>
     */
    public function all(string $content): array
    {
        if (preg_match_all(self::PROPS_REGEX, $content, $matches) < 1) {
            return [];
        }

        return $matches[0];
    }
}
