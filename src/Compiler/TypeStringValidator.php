<?php

declare(strict_types=1);

namespace Bladestan\Compiler;

use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ParserException;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\Type\Type;
use function array_key_exists;

/**
 * The one safe gateway from a signature's PHPDoc type string to a PHPStan
 * {@see Type}: it reports whether the parser accepts a type ({@see isValid()})
 * and resolves an accepted type without ever throwing out of a rule
 * ({@see resolve()}).
 *
 * A signature author can easily paste a type that looks right but the parser
 * rejects (a template placeholder like `TModel (class ..., argument)`, an
 * accessory type like `hasOffsetValue(...)`), typically copied from `dumpType`
 * output. Left unchecked, that string reaches `TypeStringResolver::resolve()`
 * inside a rule and throws, aborting the whole run and silently hiding every
 * other result. Validating first keeps one bad type from doing that.
 *
 * The parse mirrors `TypeStringResolver::resolve()`: tokenize, parse a type,
 * then require the tokens to be fully consumed. A trailing token (the common
 * failure mode) makes `consumeTokenType()` throw the same `ParserException`
 * the resolver would.
 */
final class TypeStringValidator
{
    /**
     * Memoized resolutions. A rule instance lives for the whole analysis run,
     * and the same signature type is resolved at every call site of a template,
     * so a null entry also records a rejected type parsed only once.
     *
     * @var array<string, Type|null>
     */
    private array $resolvedTypeCache = [];

    public function __construct(
        private readonly Lexer $lexer,
        private readonly TypeParser $typeParser,
        private readonly TypeStringResolver $typeStringResolver,
    ) {
    }

    public function isValid(string $typeString): bool
    {
        try {
            $tokens = new TokenIterator($this->lexer->tokenize($typeString));
            $this->typeParser->parse($tokens);
            $tokens->consumeTokenType(Lexer::TOKEN_END);
        } catch (ParserException) {
            return false;
        }

        return true;
    }

    /**
     * Resolve a PHPDoc type string into a PHPStan Type, or null when the string
     * is not a valid PHPDoc type.
     *
     * Uses TypeStringResolver, which does not require a file context (unlike
     * FileTypeMapper, which cannot resolve types for .blade.php files). The
     * resolver throws on malformed types; guarding it with isValid() contains
     * that failure here so a single bad type surfaces as a localized error at
     * the call site rather than aborting the whole analysis.
     */
    public function resolve(string $typeString): ?Type
    {
        if (array_key_exists($typeString, $this->resolvedTypeCache)) {
            return $this->resolvedTypeCache[$typeString];
        }

        if (! $this->isValid($typeString)) {
            return $this->resolvedTypeCache[$typeString] = null;
        }

        return $this->resolvedTypeCache[$typeString] = $this->typeStringResolver->resolve($typeString);
    }
}
