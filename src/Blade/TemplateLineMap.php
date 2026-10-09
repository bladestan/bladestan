<?php

declare(strict_types=1);

namespace Bladestan\Blade;

/**
 * Maps a line of compiled PHP back to the template line it came from.
 *
 * {@see FileNameAndLineNumberAddingPreCompiler} prefixes each template line
 * with a `/** file: …, line: N *&#47;` comment before compilation, and
 * {@see PhpLineToTemplateLineResolver} reads those back off the compiled AST.
 * That gives an anchor only for the compiled lines that begin a template line;
 * everything Blade expands in between (a directive that compiles to several
 * statements, the closing brace of a `@foreach`) falls to the nearest anchor
 * above it, which is the template line that produced it.
 *
 * Lines above the first anchor are the compiled preamble: the `@var`
 * annotations for the signature, composer data, and shared variables. They have
 * no template counterpart, so they anchor to line 1 rather than to whatever the
 * template happens to have on that line.
 */
final class TemplateLineMap
{
    /**
     * @param array<int, int> $anchors Compiled line => template line, ascending by key
     */
    private function __construct(
        private readonly array $anchors,
    ) {
    }

    /**
     * @param array<int, array<string, int>> $phpToTemplateLines As produced by
     *        PhpLineToTemplateLineResolver: compiled line => [template file => template line]
     */
    public static function fromResolvedLines(array $phpToTemplateLines): self
    {
        $anchors = [];
        foreach ($phpToTemplateLines as $phpLine => $templateLines) {
            $templateLine = reset($templateLines);
            if ($templateLine === false) {
                continue;
            }

            $anchors[$phpLine] = $templateLine;
        }

        ksort($anchors);

        return new self($anchors);
    }

    public function templateLine(int $phpLine): int
    {
        $found = 1;

        foreach ($this->anchors as $anchorLine => $templateLine) {
            if ($anchorLine > $phpLine) {
                break;
            }

            $found = $templateLine;
        }

        return $found;
    }
}
