<?php

declare(strict_types=1);

namespace Bladestan\Compiler;

/**
 * Blanks out the regions Blade itself excludes from directive processing before
 * they reach a directive scanner: `{{-- ... --}}` comments and
 * `@verbatim ... @endverbatim` blocks.
 *
 * `SignatureExtractor` and `ComponentScopeResolver` locate `@bladestan-signature`,
 * `@extends`, and `@props` by scanning the raw template text. Blade compiles
 * comments to nothing and never runs directive processing on verbatim content,
 * so text in those regions is not real template code. Scanning it directly would
 * let a `@bladestan-signature` docblock parked inside a comment become the
 * template's enforced contract, or a commented-out `@extends` drive signature
 * merging. Masking first makes those regions invisible to the scanners, exactly
 * as they are to Blade.
 *
 * Each masked region is replaced with whitespace of the same byte length, with
 * newlines kept in place. The result has identical length and line structure to
 * the input, so a match offset found in the masked string points at the same
 * byte in the original. That lets callers strip a matched block out of the
 * original content by offset without the mask shifting anything.
 *
 * @see \Bladestan\Tests\Compiler\BladeInertRegionMaskerTest
 */
final class BladeInertRegionMasker
{
    /**
     * A `{{-- ... --}}` comment. Mirrors Blade's own comment pattern (the
     * default `{{`/`}}` content tags); non-greedy so it stops at the first
     * closing `--}}`, exactly as Blade does.
     */
    private const COMMENT_REGEX = '/\{\{--.*?--\}\}/s';

    /**
     * A `@verbatim ... @endverbatim` block. The negative lookbehind mirrors
     * Blade: `@@verbatim` is an escaped literal, not a block opener.
     */
    private const VERBATIM_REGEX = '/(?<!@)@verbatim.*?@endverbatim/s';

    /**
     * Return $bladeContent with comment and verbatim regions replaced by
     * length-preserving whitespace. Byte offsets and line numbers are unchanged.
     *
     * Verbatim is masked before comments, matching Blade's order (it stores
     * verbatim blocks before compiling comments), so the two agree on how an
     * overlap between the constructs resolves.
     */
    public function mask(string $bladeContent): string
    {
        $masked = preg_replace_callback(
            self::VERBATIM_REGEX,
            static fn (array $matches): string => self::blank($matches[0]),
            $bladeContent,
        ) ?? $bladeContent;

        return preg_replace_callback(
            self::COMMENT_REGEX,
            static fn (array $matches): string => self::blank($matches[0]),
            $masked,
        ) ?? $masked;
    }

    /**
     * Replace every non-newline byte with a space, keeping newlines, so the
     * region's length and line count are preserved.
     */
    private static function blank(string $text): string
    {
        return preg_replace('/[^\n]/', ' ', $text) ?? $text;
    }
}
