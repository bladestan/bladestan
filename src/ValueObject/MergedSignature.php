<?php

declare(strict_types=1);

namespace Bladestan\ValueObject;

/**
 * The result of merging a template's signature with its `@extends` chain: the
 * merged contract a call site is validated against, plus any errors raised
 * while merging (a covariance violation, or a parent template that cannot be
 * resolved).
 *
 * It also records what the merge read, so whoever validates against it can
 * declare those reads to PHPStan's result cache: the templates whose signatures
 * were merged, and the files that do not exist yet but would change the chain
 * if they were created (a missing parent, or one that would shadow a parent).
 */
final class MergedSignature
{
    /**
     * @param list<string> $errors
     * @param list<string> $templateFiles every template in the chain, the starting one first
     * @param list<string> $candidateFiles absent files whose creation would change the chain
     */
    public function __construct(
        public readonly TemplateSignature $signature,
        public readonly array $errors = [],
        public readonly array $templateFiles = [],
        public readonly array $candidateFiles = [],
    ) {
    }
}
