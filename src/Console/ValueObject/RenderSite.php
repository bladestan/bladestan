<?php

declare(strict_types=1);

namespace Bladestan\Console\ValueObject;

/**
 * A `view()` / `View::make()` / `->view()` / `->markdown()` call that names a
 * template as a string literal and passes a data argument, located precisely
 * enough to inject a `\PHPStan\dumpType()` of its data before the statement.
 */
final class RenderSite
{
    public function __construct(
        public readonly string $filePath,
        public readonly string $viewName,
        /**
         * Byte offset of the first character of the data argument expression.
         */
        public readonly int $dataStartPos,
        /**
         * Byte offset of the last character of the data argument expression.
         */
        public readonly int $dataEndPos,
        /**
         * Byte offset where the enclosing statement begins, i.e. where the
         * injected dump is placed so it evaluates the same data in scope.
         */
        public readonly int $statementStartPos,
    ) {
    }
}
