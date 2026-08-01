<?php

declare(strict_types=1);

namespace Bladestan\ValueObject;

final class PhpFileContentsWithLineMap
{
    /**
     * @param array<int, array<string, int>> $phpToTemplateLines
     * @param list<string> $componentClasses Classes of the components this template renders, whose
     *                                       reflected signature is baked into the compiled output
     */
    public function __construct(
        public readonly string $phpFileContents,
        public readonly array $phpToTemplateLines,
        /**
         * @var list<array<int, string>>
         */
        public array $errors,
        public readonly array $componentClasses = [],
    ) {
    }
}
