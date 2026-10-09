<?php

declare(strict_types=1);

namespace Bladestan\ValueObject;

final class PhpFileContentsWithLineMap
{
    /**
     * @param array<int, array<string, int>> $phpToTemplateLines Compiled line => [template file => template line]
     * @param list<array{0: string, 1: string}> $errors Compilation failures as [message, identifier]
     * @param list<string> $classDependencies Classes whose declarations shaped the output, existing or not
     * @param list<string> $environmentDependencies Keys of the application configuration that shaped the output
     */
    public function __construct(
        public readonly string $phpFileContents,
        public readonly array $phpToTemplateLines,
        public readonly array $errors,
        public readonly array $classDependencies = [],
        public readonly array $environmentDependencies = [],
    ) {
    }
}
