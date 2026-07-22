<?php

declare(strict_types=1);

namespace Bladestan\Compiler;

use Bladestan\ValueObject\TemplateSignature;
use PHPStan\PhpDocParser\Ast\PhpDoc\InvalidTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\VarTagValueNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use PHPStan\PhpDocParser\Printer\Printer;

/**
 * Extracts template signatures from blade files.
 *
 * The signature blocks are located with fast string/regex scanning; the @var
 * tags inside them are parsed with PHPStan's own PHPDoc parser, so any type it
 * understands (including closure signatures whose parameters carry `$` names)
 * is read exactly as PHPStan will later interpret it.
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
     * Matches a @php ... @endphp block containing a docblock with
     * @bladestan-signature anywhere among its lines (a leading description is
     * fine). Group 1 is the docblock itself.
     */
    private const EXPLICIT_SIGNATURE_REGEX = '/@php\s*\n\s*(\/\*\*(?:(?!\*\/)[\s\S])*?@bladestan-signature\b[\s\S]*?\*\/)\s*\n\s*@endphp/';

    /**
     * Matches a standalone docblock with @bladestan-signature (without @php wrapper).
     * This handles cases where the docblock is inside a @php block that also contains other code,
     * or in compiled/raw PHP contexts.
     */
    private const EXPLICIT_SIGNATURE_DOCBLOCK_REGEX = '/\/\*\*(?:(?!\*\/)[\s\S])*?@bladestan-signature\b[\s\S]*?\*\//';

    /**
     * Same as {@see EXPLICIT_SIGNATURE_REGEX}, but also consumes the single newline
     * `buildSignature()` always writes right after `@endphp`. Stripping must remove
     * exactly what was added, or that newline accumulates as a blank line on every
     * regenerate.
     */
    private const STRIP_EXPLICIT_SIGNATURE_REGEX = '/@php\s*\n\s*\/\*\*(?:(?!\*\/)[\s\S])*?@bladestan-signature\b[\s\S]*?\*\/\s*\n\s*@endphp\n?/';

    /**
     * Fallback for a @var line whose type PHPStan's parser rejects. The lazy
     * type group cannot handle a `$` inside the type, but an invalid type
     * still has to reach the signature so the rule can report it.
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

    private readonly Lexer $phpDocLexer;

    private readonly PhpDocParser $phpDocParser;

    private readonly Printer $phpDocPrinter;

    private readonly BladeInertRegionMasker $bladeInertRegionMasker;

    /**
     * The parser chain is built here rather than injected because this class
     * runs in three hosts: PHPStan's DI container, the plain bootstrap file,
     * and the Laravel container behind the artisan command. Constructing the
     * stateless parser (and the equally stateless inert-region masker) locally
     * keeps one canonical setup instead of three.
     */
    public function __construct()
    {
        $parserConfig = new ParserConfig([]);
        $constExprParser = new ConstExprParser($parserConfig);
        $this->phpDocLexer = new Lexer($parserConfig);
        $this->phpDocParser = new PhpDocParser(
            $parserConfig,
            new TypeParser($parserConfig, $constExprParser),
            $constExprParser,
        );
        $this->phpDocPrinter = new Printer();
        $this->bladeInertRegionMasker = new BladeInertRegionMasker();
    }

    /**
     * Extract the template signature from blade file content.
     */
    public function extract(string $bladeContent): TemplateSignature
    {
        // Scan the masked content so a signature docblock inside a comment or
        // @verbatim block is invisible, exactly as it is to Blade.
        $bladeContent = $this->bladeInertRegionMasker->mask($bladeContent);

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
        // A commented-out or @verbatim @extends is inert to Blade, so mask
        // those regions before looking for the directive that drives merging.
        $bladeContent = $this->bladeInertRegionMasker->mask($bladeContent);

        if (preg_match(self::EXTENDS_REGEX, $bladeContent, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * Strip the @bladestan-signature block from blade content so it doesn't
     * interfere with compilation. The @var tags will be re-emitted in the
     * compiled output based on the extracted signature.
     *
     * @param bool $preserveLineCount Replace the block with as many blank lines
     *   as it spanned instead of deleting them, so line numbers in the rest of
     *   the template are not shifted. The compiler needs this so reported error
     *   lines match the original template; the generator does not (it rewrites
     *   the file and wants the block gone).
     */
    public function stripSignatureBlock(string $bladeContent, bool $preserveLineCount = false): string
    {
        // Try stripping @php ... @endphp block containing the signature, plus the
        // one trailing newline buildSignature() adds after @endphp, so re-running
        // the generator is idempotent instead of growing a blank line each time.
        $stripped = $this->stripMatch(self::STRIP_EXPLICIT_SIGNATURE_REGEX, $bladeContent, $preserveLineCount);
        if ($stripped !== $bladeContent) {
            return $stripped;
        }

        // If no @php wrapper, strip just the docblock
        $stripped = $this->stripMatch(self::EXPLICIT_SIGNATURE_DOCBLOCK_REGEX, $bladeContent, $preserveLineCount);
        if ($stripped !== $bladeContent) {
            return $stripped;
        }

        return $bladeContent;
    }

    /**
     * Check whether the given blade content has an explicit @bladestan-signature.
     */
    public function hasExplicitSignature(string $bladeContent): bool
    {
        $bladeContent = $this->bladeInertRegionMasker->mask($bladeContent);

        return preg_match(self::EXPLICIT_SIGNATURE_DOCBLOCK_REGEX, $bladeContent) === 1;
    }

    /**
     * Strip an implicit first-docblock signature from blade content. Only
     * strips when the docblock actually carries @var tags (i.e. it was used
     * as the template's signature); an unrelated leading docblock is kept.
     */
    public function stripImplicitSignatureBlock(string $bladeContent, bool $preserveLineCount = false): string
    {
        if (preg_match(self::FIRST_PHP_DOCBLOCK_REGEX, $this->bladeInertRegionMasker->mask($bladeContent), $matches) !== 1) {
            return $bladeContent;
        }

        if ($this->extractVarTags($matches[1]) === []) {
            return $bladeContent;
        }

        return $this->stripMatch(self::FIRST_PHP_DOCBLOCK_REGEX, $bladeContent, $preserveLineCount);
    }

    /**
     * Strip static @extends directives from blade content. `@extends` is not
     * compiled as a call site — the parent's requirements are enforced at the
     * child's call sites via signature merging. Dynamic @extends($var) is
     * left alone (it compiles to a view() call that ViewCallSiteRule skips).
     */
    public function stripExtends(string $bladeContent, bool $preserveLineCount = false): string
    {
        return $this->stripMatch(self::EXTENDS_REGEX, $bladeContent, $preserveLineCount, -1);
    }

    /**
     * Remove every match of $pattern from the content, ignoring matches that
     * fall inside a comment or @verbatim block. When $preserveLineCount is true,
     * each match is replaced with as many newlines as it contained rather than
     * deleted outright, so a stripped block leaves the following lines at their
     * original line numbers.
     *
     * Matching runs against the masked content so inert regions are skipped,
     * but the mask preserves byte offsets, so the matched ranges are spliced
     * out of the original content by offset. Splices are applied from the end
     * of the string forward, keeping earlier offsets valid as bytes are removed.
     *
     * @param int $limit Maximum number of matches to strip, or -1 for all.
     */
    private function stripMatch(
        string $pattern,
        string $bladeContent,
        bool $preserveLineCount,
        int $limit = 1,
    ): string {
        $masked = $this->bladeInertRegionMasker->mask($bladeContent);

        if (preg_match_all($pattern, $masked, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) < 1) {
            return $bladeContent;
        }

        /** @var list<array{string, int}> $wholeMatches */
        $wholeMatches = array_column($matches, 0);
        if ($limit >= 0) {
            $wholeMatches = array_slice($wholeMatches, 0, $limit);
        }

        foreach (array_reverse($wholeMatches) as [$matchText, $offset]) {
            $replacement = $preserveLineCount ? str_repeat("\n", substr_count($matchText, "\n")) : '';
            $bladeContent = substr_replace($bladeContent, $replacement, $offset, strlen($matchText));
        }

        return $bladeContent;
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
        // Scan the masked content: an inert signature/@extends/@props must not
        // feed the cache hash, or a purely-commented edit would invalidate it.
        $bladeContent = $this->bladeInertRegionMasker->mask($bladeContent);

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
        // First try the @php-wrapped form (group 1 is the docblock)
        if (preg_match(self::EXPLICIT_SIGNATURE_REGEX, $bladeContent, $matches) === 1) {
            $variables = $this->extractVarTags($matches[1]);
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
     * Parsed with PHPStan's PHPDoc parser, so types are read exactly as the
     * analysis will later interpret them, including closure signatures whose
     * parameters carry `$` names and trailing tag descriptions. A @var whose
     * type the parser rejects is recovered with a best-effort regex: the
     * invalid type string must still enter the signature so the rule can
     * report it instead of silently dropping the variable.
     *
     * @return array<string, string> Variable name => type string
     */
    private function extractVarTags(string $docblock): array
    {
        $tokens = new TokenIterator($this->phpDocLexer->tokenize($docblock));
        $phpDocNode = $this->phpDocParser->parse($tokens);

        $variables = [];
        foreach ($phpDocNode->children as $child) {
            if (! $child instanceof PhpDocTagNode) {
                continue;
            }

            if ($child->name !== '@var') {
                continue;
            }

            $value = $child->value;
            if ($value instanceof VarTagValueNode) {
                $name = ltrim($value->variableName, '$');
                if ($name !== '') {
                    $variables[$name] = $this->phpDocPrinter->print($value->type);
                }

                continue;
            }

            if ($value instanceof InvalidTagValueNode) {
                $variables += $this->extractVarTagsByRegex('@var ' . $value->value);
            }
        }

        return $variables;
    }

    /**
     * @return array<string, string> Variable name => type string
     */
    private function extractVarTagsByRegex(string $docblock): array
    {
        if (preg_match_all(self::VAR_TAG_REGEX, $docblock, $matches, PREG_SET_ORDER) === 0) {
            return [];
        }

        $variables = [];
        foreach ($matches as $match) {
            $variables[$match[2]] = trim($match[1]);
        }

        return $variables;
    }
}
