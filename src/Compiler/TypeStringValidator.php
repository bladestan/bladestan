<?php

declare(strict_types=1);

namespace Bladestan\Compiler;

use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ParserException;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;

/**
 * Reports whether a signature's PHPDoc type string is one PHPStan's parser
 * accepts, before it is handed to `TypeStringResolver` or emitted into a
 * compiled template.
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
    public function __construct(
        private readonly Lexer $lexer,
        private readonly TypeParser $typeParser,
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
}
