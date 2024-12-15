<?php

declare(strict_types=1);

namespace Bladestan\Tests\PhpParser;

use Bladestan\PhpParser\ArrayStringToArrayConverter;
use Iterator;
use PHPStan\Testing\PHPStanTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ArrayStringToArrayConverterTest extends PHPStanTestCase
{
    private ArrayStringToArrayConverter $arrayStringToArrayConverter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->arrayStringToArrayConverter = self::getContainer()->getByType(ArrayStringToArrayConverter::class);
    }

    /**
     * @param array<string, mixed> $expectedArray
     */
    #[DataProvider('provideData')]
    public function testConvertArrayLikeStringToPhpArray(string $arrayString, array $expectedArray): void
    {
        $convertedArray = $this->arrayStringToArrayConverter->convert($arrayString);

        $this->assertSame($expectedArray, $convertedArray);
    }

    public static function provideData(): Iterator
    {
        yield [
            "['foo' => 'bar', 'bar' => 'baz,bax']", [
                'foo' => "'bar'",
                'bar' => "'baz,bax'",
            ]];
        yield [
            "['foo' => \$foo . 'bar']", [
                'foo' => "\$foo . 'bar'",
            ]];
        yield [
            "['foo' => \$foo->someMethod()]", [
                'foo' => '$foo->someMethod()',
            ]];

        yield ['', []];
        yield ['123', []];
        yield ["'foo'", []];
        yield ['[]', []];
        yield ['[10]', []];
        yield ["['foo', 123]", []];
        yield ["[\$foo => 'bar']", []];
    }

    /**
     * @return string[]
     */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../../config/extension.neon'];
    }
}
