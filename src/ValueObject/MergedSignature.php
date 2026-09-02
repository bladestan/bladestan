<?php

declare(strict_types=1);

namespace Bladestan\ValueObject;

/**
 * The result of merging a template's signature with its `@extends` chain: the
 * merged contract a call site is validated against, plus any errors raised
 * while merging (a covariance violation, or a parent template that cannot be
 * resolved).
 */
final class MergedSignature
{
    /**
     * @param list<string> $errors
     */
    public function __construct(
        public readonly TemplateSignature $signature,
        public readonly array $errors = [],
    ) {
    }
}
