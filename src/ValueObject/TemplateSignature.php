<?php

declare(strict_types=1);

namespace Bladestan\ValueObject;

/**
 * Represents the type contract of a blade template.
 *
 * Extracted from `@bladestan-signature` docblocks or inferred from
 * the first docblock / `@props` declarations.
 */
final class TemplateSignature
{
    /**
     * @param array<string, string> $variables Variable name => PHPDoc type string (e.g. ['name' => 'string', 'user' => '\App\Models\User'])
     * @param bool $isExplicit Whether the signature came from an explicit `@bladestan-signature` marker
     */
    public function __construct(
        public readonly array $variables,
        public readonly bool $isExplicit = false,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->variables === [];
    }

    public function hasVariable(string $name): bool
    {
        return isset($this->variables[$name]);
    }

    public function getVariableType(string $name): ?string
    {
        return $this->variables[$name] ?? null;
    }

    /**
     * @return list<string>
     */
    public function getVariableNames(): array
    {
        return array_keys($this->variables);
    }

    /**
     * Returns a new signature with additional variables merged in.
     * Existing variables are NOT overwritten.
     *
     * @param array<string, string> $additionalVariables
     */
    public function withAdditionalVariables(array $additionalVariables): self
    {
        return new self($this->variables + $additionalVariables, $this->isExplicit);
    }

    /**
     * Returns a new signature with the given variables merged in.
     * Variables from $other override variables in $this on conflict.
     */
    public function mergedWith(self $other): self
    {
        return new self([...$this->variables, ...$other->variables], $this->isExplicit || $other->isExplicit);
    }
}
