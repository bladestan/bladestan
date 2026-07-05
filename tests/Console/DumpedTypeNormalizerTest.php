<?php

declare(strict_types=1);

namespace Bladestan\Tests\Console;

use Bladestan\Console\DumpedTypeNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DumpedTypeNormalizerTest extends TestCase
{
    #[DataProvider('provideTypes')]
    public function testNormalize(string $dumped, string $expected): void
    {
        $this->assertSame($expected, (new DumpedTypeNormalizer())->normalize($dumped));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideTypes(): iterable
    {
        yield 'plain scalar' => ['string', 'string'];
        // A fictitious namespace keeps Rector's string-to-::class rule from
        // rewriting these literals; the normalizer only pattern-matches names.
        yield 'already-qualified class is left alone' => ['\Vendor\Pkg\Widget', '\Vendor\Pkg\Widget'];
        yield 'unqualified class gets a leading slash' => ['Vendor\Pkg\Widget', '\Vendor\Pkg\Widget'];
        yield 'template placeholder becomes mixed' => ['TModel (class \App\Foo, argument)', 'mixed'];
        yield 'accessory type is stripped, leaving no dangling intersection' => ["non-empty-array&hasOffsetValue('a', string)", 'non-empty-array'];
        yield 'string subtype widens to string' => ['non-empty-string', 'string'];
        yield 'list becomes array<int, T>' => ['list<App\Models\User>', 'array<int, \App\Models\User>'];
        yield 'int range widens to int' => ['int<0, max>', 'int'];
        yield 'literal string union collapses to string' => ["'foo'|'bar'", 'string'];
        yield 'parenthesized union is unwrapped' => ['(int|string)', 'int|string'];
        yield 'nullable class keeps null' => ['App\Models\Post|null', '\App\Models\Post|null'];
    }
}
