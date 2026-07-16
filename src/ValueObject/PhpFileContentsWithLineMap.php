<?php

declare(strict_types=1);

namespace Bladestan\ValueObject;

final class PhpFileContentsWithLineMap
{
    /**
     * @param array<int, array<string, int>> $phpToTemplateLines
     */
    public function __construct(
        public readonly string $phpFileContents,
        public readonly array $phpToTemplateLines,
        /**
         * @var list<array<int, string>>
         */
        public array $errors,
    ) {
    }
}
