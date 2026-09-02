<?php

declare(strict_types=1);

namespace Bladestan\Tests\Compiler;

use Bladestan\Compiler\PropsDirectiveExtractor;
use PHPUnit\Framework\TestCase;

final class PropsDirectiveExtractorTest extends TestCase
{
    private PropsDirectiveExtractor $propsDirectiveExtractor;

    protected function setUp(): void
    {
        $this->propsDirectiveExtractor = new PropsDirectiveExtractor();
    }

    public function testExtractArrayLiteralReturnsNullWithoutProps(): void
    {
        $this->assertNull($this->propsDirectiveExtractor->extractArrayLiteral('<div>{{ $slot }}</div>'));
    }

    public function testExtractArrayLiteralReturnsTheArrayIncludingBrackets(): void
    {
        $this->assertSame(
            "['type' => 'info', 'title']",
            $this->propsDirectiveExtractor->extractArrayLiteral("@props(['type' => 'info', 'title'])"),
        );
    }

    public function testExtractArrayLiteralSpansPastACallInADefault(): void
    {
        // The first ")" belongs to foo(1); the directive must not be truncated
        // there, or everything after it silently disappears.
        $this->assertSame(
            "['a' => foo(1), 'b' => 2]",
            $this->propsDirectiveExtractor->extractArrayLiteral("@props(['a' => foo(1), 'b' => 2])"),
        );
    }

    public function testExtractArrayLiteralSpansNestedArrays(): void
    {
        $this->assertSame(
            "['a' => ['x' => 1], 'b' => 2]",
            $this->propsDirectiveExtractor->extractArrayLiteral("@props(['a' => ['x' => 1], 'b' => 2])"),
        );
    }

    public function testAllReturnsEveryDirectiveWholeAndInOrder(): void
    {
        $content = "@props(['a' => foo(1)])\n<div></div>\n@props(['b'])";

        $this->assertSame(
            ["@props(['a' => foo(1)])", "@props(['b'])"],
            $this->propsDirectiveExtractor->all($content),
        );
    }

    public function testAllReturnsEmptyWithoutProps(): void
    {
        $this->assertSame([], $this->propsDirectiveExtractor->all('<div>{{ $slot }}</div>'));
    }
}
