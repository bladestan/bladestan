<?php

declare(strict_types=1);

namespace Bladestan\Tests\Console;

use Bladestan\Console\DumpedShapeParser;
use Bladestan\Console\DumpedTypeNormalizer;
use PHPUnit\Framework\TestCase;

final class DumpedShapeParserTest extends TestCase
{
    private DumpedShapeParser $dumpedShapeParser;

    protected function setUp(): void
    {
        $this->dumpedShapeParser = new DumpedShapeParser(new DumpedTypeNormalizer());
    }

    public function testParseShapeReadsMembersAndOptionalMarker(): void
    {
        $shape = $this->dumpedShapeParser->parseShape('array{title: string, post?: App\\Models\\Post}');

        $this->assertSame([
            'type' => 'string',
            'optional' => false,
        ], $shape['title'] ?? null);
        $this->assertSame([
            'type' => 'App\\Models\\Post',
            'optional' => true,
        ], $shape['post'] ?? null);
    }

    public function testParseShapeReturnsNullForNonArrayDump(): void
    {
        $this->assertNull($this->dumpedShapeParser->parseShape('string'));
    }

    public function testParseShapeKeepsCommasInsideNestedGenerics(): void
    {
        $shape = $this->dumpedShapeParser->parseShape('array{items: array<int, string>}');

        $this->assertSame(['items'], array_keys($shape ?? []));
        $this->assertSame('array<int, string>', $shape['items']['type'] ?? null);
    }

    public function testMergeShapesUnionsTypesAndNormalizes(): void
    {
        $merged = $this->dumpedShapeParser->mergeShapes([
            [
                'title' => [
                    'type' => 'string',
                    'optional' => false,
                ],
            ],
            [
                'title' => [
                    'type' => 'App\\Models\\Title',
                    'optional' => false,
                ],
            ],
        ]);

        $this->assertSame('string|\App\Models\Title', $merged['title'] ?? null);
    }

    public function testMergeShapesMakesVariableNullableWhenAbsentFromSomeSite(): void
    {
        $merged = $this->dumpedShapeParser->mergeShapes([
            [
                'title' => [
                    'type' => 'string',
                    'optional' => false,
                ],
                'post' => [
                    'type' => 'string',
                    'optional' => false,
                ],
            ],
            [
                'title' => [
                    'type' => 'string',
                    'optional' => false,
                ],
            ],
        ]);

        // $post is passed by only one of the two sites, so it may be absent.
        $this->assertSame('string', $merged['title'] ?? null);
        $this->assertSame('string|null', $merged['post'] ?? null);
    }
}
