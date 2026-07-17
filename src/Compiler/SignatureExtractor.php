<?php

declare(strict_types=1);

namespace Bladestan\Compiler;

use Bladestan\ValueObject\TemplateSignature;

/**
 * Extracts template signatures from blade files via fast string/regex scanning.
 *
 * Resolution priority:
 * 1. `@bladestan-signature` docblock (explicit)
 * 2. First docblock before any template code (implicit)
 * 3. Empty signature (variables will be untyped)
 *
 * @see \Bladestan\Tests\Compiler\SignatureExtractorTest
 */
final class SignatureExtractor
{
    /**
     * Matches a @php ... @endphp block containing a docblock with @bladestan-signature.
     *
     * @see https://regex101.com/r/xQ3mR7/1
     */
    private const EXPLICIT_SIGNATURE_REGEX = '/@php\s*\n\s*\/\*\*\s*\n\s*\*\s*@bladestan-signature\b.*?\*\/\s*\n\s*@endphp/s';

    /**
     * Matches a standalone docblock with @bladestan-signature (without @php wrapper).
     * This handles cases where the docblock is inside a @php block that also contains other code,
     * or in compiled/raw PHP contexts.
     */
    private const EXPLICIT_SIGNATURE_DOCBLOCK_REGEX = '/\/\*\*\s*\n\s*\*\s*@bladestan-signature\b.*?\*\//s';

    /**
     * Same as {@see EXPLICIT_SIGNATURE_REGEX}, but also consumes the single newline
     * `buildSignature()` always writes right after `@endphp`. Stripping must remove
     * exactly what was added, or that newline accumulates as a blank line on every
     * regenerate.
     */
    private const STRIP_EXPLICIT_SIGNATURE_REGEX = '/@php\s*\n\s*\/\*\*\s*\n\s*\*\s*@bladestan-signature\b.*?\*\/\s*\n\s*@endphp\n?/s';

    /**
     * Matches @var Type in a docblock.
     *
     * @see https://regex101.com/r/kL9pQ2/1
     */
    private const VAR_TAG_REGEX = '/@var\s+(.+?)\s+\$([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)/';

    /**
     * Matches the first @php /** ... * / @endphp block in the file (for implicit signature fallback).
     */
    private const FIRST_PHP_DOCBLOCK_REGEX = '/\A\s*(?:{{--.*?--}}\s*)*@php\s*\n(\s*\/\*\*.*?\*\/)\s*\n\s*@endphp/s';

    /**
     * Matches @extends('name') or @extends("name") directives.
     *
     * @see https://regex101.com/r/hN7qR3/1
     */
    private const EXTENDS_REGEX = '/@extends\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/';

    /**
     * Matches @props([...]) directives (single-line form).
     */
    private const PROPS_REGEX = '/@props\s*\(.*?\)/s';

    /**
     * Extract the template signature from blade file content.
     */
    public function extract(string $bladeContent): TemplateSignature
    {
        // Priority 1: Explicit @bladestan-signature
        $explicit = $this->extractExplicitSignature($bladeContent);
        if ($explicit instanceof TemplateSignature) {
            return $explicit;
        }

        // Priority 2: First docblock before any template code (implicit)
        $implicit = $this->extractImplicitSignature($bladeContent);
        if ($implicit instanceof TemplateSignature) {
            return $implicit;
        }

        // Priority 3: Empty signature
        return new TemplateSignature([]);
    }

    /**
     * Find the @extends directive in a blade file, if any.
     *
     * @return string|null The view name of the parent template, or null if no @extends found
     */
    public function findExtends(string $bladeContent): ?string
    {
        if (preg_match(self::EXTENDS_REGEX, $bladeContent, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * Strip the @bladestan-signature block from blade content so it doesn't
     * interfere with compilation. The @var tags will be re-emitted in the
     * compiled output based on the extracted signature.
     */
    public function stripSignatureBlock(string $bladeContent): string
    {
        // Try stripping @php ... @endphp block containing the signature, plus the
        // one trailing newline buildSignature() adds after @endphp, so re-running
        // the generator is idempotent instead of growing a blank line each time.
        $stripped = preg_replace(self::STRIP_EXPLICIT_SIGNATURE_REGEX, '', $bladeContent, 1);
        if ($stripped !== null && $stripped !== $bladeContent) {
            return $stripped;
        }

        // If no @php wrapper, strip just the docblock
        $stripped = preg_replace(self::EXPLICIT_SIGNATURE_DOCBLOCK_REGEX, '', $bladeContent, 1);
        if ($stripped !== null && $stripped !== $bladeContent) {
            return $stripped;
        }

        return $bladeContent;
    }

    /**
     * Check whether the given blade content has an explicit @bladestan-signature.
     */
    public function hasExplicitSignature(string $bladeContent): bool
    {
        return preg_match(self::EXPLICIT_SIGNATURE_DOCBLOCK_REGEX, $bladeContent) === 1;
    }

    /**
     * Strip an implicit first-docblock signature from blade content. Only
     * strips when the docblock actually carries @var tags (i.e. it was used
     * as the template's signature); an unrelated leading docblock is kept.
     */
    public function stripImplicitSignatureBlock(string $bladeContent): string
    {
        if (preg_match(self::FIRST_PHP_DOCBLOCK_REGEX, $bladeContent, $matches) !== 1) {
            return $bladeContent;
        }

        if ($this->extractVarTags($matches[1]) === []) {
            return $bladeContent;
        }

        return preg_replace(self::FIRST_PHP_DOCBLOCK_REGEX, '', $bladeContent, 1) ?? $bladeContent;
    }

    /**
     * Strip static @extends directives from blade content. `@extends` is not
     * compiled as a call site — the parent's requirements are enforced at the
     * child's call sites via signature merging. Dynamic @extends($var) is
     * left alone (it compiles to a view() call that ViewCallSiteRule skips).
     */
    public function stripExtends(string $bladeContent): string
    {
        return preg_replace(self::EXTENDS_REGEX, '', $bladeContent) ?? $bladeContent;
    }

    /**
     * Extract the signature-relevant slices of a blade file: the signature
     * docblock, @extends directives, and @props declarations. Used by the
     * result-cache meta extension — hashing only these slices means template
     * *body* edits don't invalidate the whole result cache (those are tracked
     * via the compiled file's own hash).
     */
    public function extractSignatureRelevantContent(string $bladeContent): string
    {
        $slices = [];

        if (preg_match(self::EXPLICIT_SIGNATURE_DOCBLOCK_REGEX, $bladeContent, $matches) === 1) {
            $slices[] = $matches[0];
        } elseif (preg_match(self::FIRST_PHP_DOCBLOCK_REGEX, $bladeContent, $matches) === 1) {
            $slices[] = $matches[1];
        }

        if (preg_match_all(self::EXTENDS_REGEX, $bladeContent, $matches) > 0) {
            $slices = array_merge($slices, $matches[0]);
        }

        if (preg_match_all(self::PROPS_REGEX, $bladeContent, $matches) > 0) {
            $slices = array_merge($slices, $matches[0]);
        }

        return implode("\n", $slices);
    }

    private function extractExplicitSignature(string $bladeContent): ?TemplateSignature
    {
        // First try the @php-wrapped form
        if (preg_match(self::EXPLICIT_SIGNATURE_REGEX, $bladeContent, $matches) === 1) {
            $variables = $this->extractVarTags($matches[0]);
            return new TemplateSignature($variables, isExplicit: true);
        }

        // Then try standalone docblock
        if (preg_match(self::EXPLICIT_SIGNATURE_DOCBLOCK_REGEX, $bladeContent, $matches) === 1) {
            $variables = $this->extractVarTags($matches[0]);
            return new TemplateSignature($variables, isExplicit: true);
        }

        return null;
    }

    private function extractImplicitSignature(string $bladeContent): ?TemplateSignature
    {
        if (preg_match(self::FIRST_PHP_DOCBLOCK_REGEX, $bladeContent, $matches) !== 1) {
            return null;
        }

        $docblock = $matches[1];
        $variables = $this->extractVarTags($docblock);

        if ($variables === []) {
            return null;
        }

        return new TemplateSignature($variables, isExplicit: false);
    }

    /**
     * Extract all @var Type declarations from a docblock string.
     *
     * @return array<string, string> Variable name => type string
     */
    private function extractVarTags(string $docblock): array
    {
        $variables = [];

        if (preg_match_all(self::VAR_TAG_REGEX, $docblock, $matches, PREG_SET_ORDER) === 0) {
            return [];
        }

        foreach ($matches as $match) {
            $type = trim($match[1]);
            $name = $match[2];
            $variables[$name] = $type;
        }

        return $variables;
    }
}
