<?php

declare(strict_types=1);

namespace Bladestan\Tests\Compiler;

use Bladestan\Compiler\SignatureExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SignatureExtractorTest extends TestCase
{
    private SignatureExtractor $signatureExtractor;

    protected function setUp(): void
    {
        $this->signatureExtractor = new SignatureExtractor();
    }

    public function testExtractExplicitSignature(): void
    {
        $bladeContent = <<<'BLADE'
            @php
            /**
             * @bladestan-signature
             * @var string $name
             * @var \App\Models\User $user
             */
            @endphp

            <h1>Hello {{ $name }}</h1>
            BLADE;

        $templateSignature = $this->signatureExtractor->extract($bladeContent);

        $this->assertTrue($templateSignature->isExplicit);
        $this->assertSame([
            'name' => 'string',
            'user' => '\App\Models\User',
        ], $templateSignature->variables);
    }

    public function testExtractExplicitSignatureWithNullableType(): void
    {
        $bladeContent = <<<'BLADE'
            @php
            /**
             * @bladestan-signature
             * @var ?string $title
             * @var \App\Models\User $user
             */
            @endphp
            BLADE;

        $templateSignature = $this->signatureExtractor->extract($bladeContent);

        $this->assertTrue($templateSignature->isExplicit);
        $this->assertSame([
            'title' => '?string',
            'user' => '\App\Models\User',
        ], $templateSignature->variables);
    }

    public function testExtractExplicitSignatureWithUnionType(): void
    {
        $bladeContent = <<<'BLADE'
            @php
            /**
             * @bladestan-signature
             * @var string|int $id
             */
            @endphp
            BLADE;

        $templateSignature = $this->signatureExtractor->extract($bladeContent);

        $this->assertTrue($templateSignature->isExplicit);
        $this->assertSame([
            'id' => 'string|int',
        ], $templateSignature->variables);
    }

    public function testExtractImplicitSignatureFromFirstDocblock(): void
    {
        $bladeContent = <<<'BLADE'
            @php
            /** @var string $name */
            @endphp

            <h1>{{ $name }}</h1>
            BLADE;

        $templateSignature = $this->signatureExtractor->extract($bladeContent);

        $this->assertFalse($templateSignature->isExplicit);
        $this->assertSame([
            'name' => 'string',
        ], $templateSignature->variables);
    }

    public function testExtractImplicitSignatureMultilineDocblock(): void
    {
        $bladeContent = <<<'BLADE'
            @php
            /**
             * @var string $name
             * @var int $age
             */
            @endphp

            <h1>{{ $name }} ({{ $age }})</h1>
            BLADE;

        $templateSignature = $this->signatureExtractor->extract($bladeContent);

        $this->assertFalse($templateSignature->isExplicit);
        $this->assertSame([
            'name' => 'string',
            'age' => 'int',
        ], $templateSignature->variables);
    }

    public function testExtractEmptySignatureWhenNoDocblock(): void
    {
        $bladeContent = <<<'BLADE'
            <h1>Hello world</h1>
            <p>No variables here.</p>
            BLADE;

        $templateSignature = $this->signatureExtractor->extract($bladeContent);

        $this->assertFalse($templateSignature->isExplicit);
        $this->assertTrue($templateSignature->isEmpty());
    }

    public function testExtractEmptySignatureWhenDocblockHasNoVarTags(): void
    {
        $bladeContent = <<<'BLADE'
            @php
            /** This is just a comment. */
            @endphp

            <h1>Hello</h1>
            BLADE;

        $templateSignature = $this->signatureExtractor->extract($bladeContent);

        $this->assertTrue($templateSignature->isEmpty());
    }

    public function testExplicitSignatureTakesPriorityOverImplicit(): void
    {
        $bladeContent = <<<'BLADE'
            @php
            /**
             * @bladestan-signature
             * @var string $name
             */
            @endphp

            @php
            /** @var int $age */
            @endphp
            BLADE;

        $templateSignature = $this->signatureExtractor->extract($bladeContent);

        $this->assertTrue($templateSignature->isExplicit);
        $this->assertSame([
            'name' => 'string',
        ], $templateSignature->variables);
        $this->assertFalse($templateSignature->hasVariable('age'));
    }

    public function testHasExplicitSignature(): void
    {
        $withSignature = <<<'BLADE'
            @php
            /**
             * @bladestan-signature
             * @var string $name
             */
            @endphp
            BLADE;

        $withoutSignature = '<h1>Hello</h1>';

        $this->assertTrue($this->signatureExtractor->hasExplicitSignature($withSignature));
        $this->assertFalse($this->signatureExtractor->hasExplicitSignature($withoutSignature));
    }

    public function testStripSignatureBlockRemovesExplicitBlock(): void
    {
        $bladeContent = <<<'BLADE'
            @php
            /**
             * @bladestan-signature
             * @var string $name
             */
            @endphp

            <h1>{{ $name }}</h1>
            BLADE;

        $stripped = $this->signatureExtractor->stripSignatureBlock($bladeContent);

        $this->assertStringNotContainsString('@bladestan-signature', $stripped);
        $this->assertStringContainsString('<h1>{{ $name }}</h1>', $stripped);
    }

    public function testStripSignatureBlockRoundTripsWithoutGrowingBlankLines(): void
    {
        // Mirrors the exact shape GenerateBladeSignaturesCommand::buildSignature()
        // writes (4-space indent, one trailing newline after @endphp), so stripping
        // it must reproduce the pre-signature content exactly. Otherwise each
        // `--force` regenerate leaves one more blank line than the last.
        // buildSignature() always emits the block followed by exactly one "\n"; the
        // blank line here is the template's own content (e.g. left by blade-formatter)
        // and must survive stripping untouched rather than being eaten as part of it.
        $body = "\n<div>\n    hello\n</div>";
        $signatureBlock = "@php\n    /**\n     * @bladestan-signature\n     * @var string \$name\n     */\n@endphp\n";

        $stripped = $this->signatureExtractor->stripSignatureBlock($signatureBlock . $body);

        $this->assertSame($body, $stripped);
    }

    public function testStripSignatureBlockLeavesContentWithoutSignatureUnchanged(): void
    {
        $bladeContent = '<h1>Hello</h1>';

        $stripped = $this->signatureExtractor->stripSignatureBlock($bladeContent);

        $this->assertSame($bladeContent, $stripped);
    }

    public function testFindExtendsDirective(): void
    {
        $bladeContent = <<<'BLADE'
            @extends('layouts.app')

            @section('content')
            <h1>Hello</h1>
            @endsection
            BLADE;

        $parent = $this->signatureExtractor->findExtends($bladeContent);

        $this->assertSame('layouts.app', $parent);
    }

    public function testFindExtendsDirectiveWithDoubleQuotes(): void
    {
        $bladeContent = '@extends("layouts.app")';

        $parent = $this->signatureExtractor->findExtends($bladeContent);

        $this->assertSame('layouts.app', $parent);
    }

    public function testFindExtendsReturnsNullWhenNoExtends(): void
    {
        $bladeContent = '<h1>Hello</h1>';

        $parent = $this->signatureExtractor->findExtends($bladeContent);

        $this->assertNull($parent);
    }

    public function testImplicitSignatureIgnoresDocblocksAfterContent(): void
    {
        $bladeContent = <<<'BLADE'
            <h1>Hello</h1>

            @php
            /** @var string $name */
            @endphp
            BLADE;

        $templateSignature = $this->signatureExtractor->extract($bladeContent);

        // The docblock comes AFTER template content, so it's not treated as implicit signature
        $this->assertTrue($templateSignature->isEmpty());
    }

    public function testImplicitSignatureAllowsLeadingBladeComments(): void
    {
        $bladeContent = <<<'BLADE'
            {{-- This is a blade comment --}}
            @php
            /** @var string $name */
            @endphp

            <h1>{{ $name }}</h1>
            BLADE;

        $templateSignature = $this->signatureExtractor->extract($bladeContent);

        $this->assertFalse($templateSignature->isExplicit);
        $this->assertSame([
            'name' => 'string',
        ], $templateSignature->variables);
    }

    /**
     * @param array<string, string> $expectedVariables
     */
    #[DataProvider('provideComplexTypeData')]
    public function testExtractComplexTypes(string $varTag, array $expectedVariables): void
    {
        $bladeContent = <<<BLADE
            @php
            /**
             * @bladestan-signature
             * {$varTag}
             */
            @endphp
            BLADE;

        $templateSignature = $this->signatureExtractor->extract($bladeContent);

        $this->assertSame($expectedVariables, $templateSignature->variables);
    }

    /**
     * @return iterable<string, array{string, array<string, string>}>
     */
    public static function provideComplexTypeData(): iterable
    {
        yield 'array type' => [
            '@var array<string, int> $items',
            [
                'items' => 'array<string, int>',
            ],
        ];

        yield 'collection type' => [
            '@var \Illuminate\Support\Collection<int, \App\Models\User> $users',
            [
                'users' => '\Illuminate\Support\Collection<int, \App\Models\User>',
            ],
        ];

        yield 'intersection type' => [
            '@var \Countable&\Iterator $iter',
            [
                'iter' => '\Countable&\Iterator',
            ],
        ];

        yield 'closure type with named parameter' => [
            '@var \Closure(\App\Models\User $user): string $callback',
            [
                'callback' => '\Closure(\App\Models\User $user): string',
            ],
        ];

        yield 'type with trailing description' => [
            '@var string $title The page title',
            [
                'title' => 'string',
            ],
        ];
    }

    public function testExplicitSignatureMarkerAfterDescriptionLine(): void
    {
        $bladeContent = <<<'BLADE'
            @php
            /**
             * The user profile card.
             *
             * @bladestan-signature
             * @var string $name
             */
            @endphp

            <h1>{{ $name }}</h1>
            BLADE;

        $templateSignature = $this->signatureExtractor->extract($bladeContent);

        $this->assertTrue($templateSignature->isExplicit);
        $this->assertSame([
            'name' => 'string',
        ], $templateSignature->variables);
        $this->assertTrue($this->signatureExtractor->hasExplicitSignature($bladeContent));
        $this->assertStringNotContainsString(
            '@bladestan-signature',
            $this->signatureExtractor->stripSignatureBlock($bladeContent),
        );
    }
}
