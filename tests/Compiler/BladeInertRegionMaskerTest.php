<?php

declare(strict_types=1);

namespace Bladestan\Tests\Compiler;

use Bladestan\Compiler\BladeInertRegionMasker;
use PHPUnit\Framework\TestCase;

final class BladeInertRegionMaskerTest extends TestCase
{
    private BladeInertRegionMasker $bladeInertRegionMasker;

    protected function setUp(): void
    {
        $this->bladeInertRegionMasker = new BladeInertRegionMasker();
    }

    public function testBlanksCommentContentWhileKeepingLength(): void
    {
        $comment = "{{-- @extends('old') --}}";
        $blade = '<h1>Hi</h1>' . $comment . '<p>Bye</p>';

        $masked = $this->bladeInertRegionMasker->mask($blade);

        $this->assertSame('<h1>Hi</h1>' . str_repeat(' ', strlen($comment)) . '<p>Bye</p>', $masked);
        $this->assertSame(strlen($blade), strlen($masked));
    }

    public function testBlanksVerbatimContent(): void
    {
        $blade = "@verbatim\n@props(['a'])\n@endverbatim";

        $masked = $this->bladeInertRegionMasker->mask($blade);

        $this->assertStringNotContainsString('@props', $masked);
        $this->assertSame(strlen($blade), strlen($masked));
    }

    public function testPreservesNewlinesAndLineNumbers(): void
    {
        $blade = "line1\n{{-- a\nb\nc --}}\nline5";

        $masked = $this->bladeInertRegionMasker->mask($blade);

        $this->assertSame(substr_count($blade, "\n"), substr_count($masked, "\n"));
        // Every original newline stays at the same offset, so line numbers hold.
        $this->assertSame("line1\n", substr($masked, 0, 6));
        $this->assertSame("\nline5", substr($masked, -6));
    }

    public function testLeavesRealDirectivesUntouched(): void
    {
        $blade = "@extends('layout')\n@props(['a'])\n{{ \$name }}";

        $this->assertSame($blade, $this->bladeInertRegionMasker->mask($blade));
    }

    public function testEscapedVerbatimIsNotTreatedAsABlock(): void
    {
        // @@verbatim is Blade's escape for a literal @verbatim, so it opens no block.
        $blade = "@@verbatim @extends('layout') @@endverbatim";

        $this->assertSame($blade, $this->bladeInertRegionMasker->mask($blade));
    }

    public function testStopsAtFirstClosingCommentMarker(): void
    {
        $blade = "{{-- one --}}@extends('layout'){{-- two --}}";

        $masked = $this->bladeInertRegionMasker->mask($blade);

        $this->assertStringContainsString("@extends('layout')", $masked);
        $this->assertStringNotContainsString('one', $masked);
        $this->assertStringNotContainsString('two', $masked);
    }

    public function testKeepsPhpBlocksByDefault(): void
    {
        // The signature scan relies on seeing its docblock inside a @php block,
        // so @php content is left untouched unless masking is requested.
        $blade = "@php \$x = \"@extends('layout')\"; @endphp";

        $this->assertSame($blade, $this->bladeInertRegionMasker->mask($blade));
    }

    public function testBlanksPhpBlockContentWhenRequested(): void
    {
        $blade = "@php \$x = \"@extends('layout')\"; @endphp";

        $masked = $this->bladeInertRegionMasker->mask($blade, maskPhpBlocks: true);

        $this->assertStringNotContainsString('@extends', $masked);
        $this->assertSame(strlen($blade), strlen($masked));
    }

    public function testEscapedPhpIsNotTreatedAsABlock(): void
    {
        // @@php is Blade's escape for a literal @php, so it opens no block.
        $blade = "@@php @extends('layout') @@endphp";

        $this->assertSame($blade, $this->bladeInertRegionMasker->mask($blade, maskPhpBlocks: true));
    }

    public function testPhpMaskingPreservesNewlinesAndLineNumbers(): void
    {
        $blade = "line1\n@php\n@extends('x')\n@endphp\nline5";

        $masked = $this->bladeInertRegionMasker->mask($blade, maskPhpBlocks: true);

        $this->assertStringNotContainsString('@extends', $masked);
        $this->assertSame(substr_count($blade, "\n"), substr_count($masked, "\n"));
        $this->assertSame("line1\n", substr($masked, 0, 6));
        $this->assertSame("\nline5", substr($masked, -6));
    }
}
