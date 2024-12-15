<?php

declare(strict_types=1);

namespace Bladestan\Tests\Blade;

use Bladestan\Blade\PhpLineToTemplateLineResolver;
use Iterator;
use PHPStan\Testing\PHPStanTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class PhpLineToTemplateLineResolverTest extends PHPStanTestCase
{
    private PhpLineToTemplateLineResolver $phpLineToTemplateLineResolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->phpLineToTemplateLineResolver = self::getContainer()->getByType(PhpLineToTemplateLineResolver::class);
    }

    /**
     * @param mixed[] $expectedPhpToTemplateLineMapping
     */
    #[DataProvider('provideData')]
    public function test(string $phpContent, array $expectedPhpToTemplateLineMapping): void
    {
        $phpToTemplateLineMapping = $this->phpLineToTemplateLineResolver->resolve($phpContent);

        $this->assertSame($expectedPhpToTemplateLineMapping, $phpToTemplateLineMapping);
    }

    public static function provideData(): Iterator
    {
        yield 'File with no contents' => ['', []];

        yield 'File with no comments' => ["<?php echo 'foo';", []];

        yield 'File with wrong comment style' => [
            <<<'PHP'
                <?php
                // file: foo.blade.php, line: 5 */
                echo 'foo';
                /* file: foo.blade.php, line: 6 */
                echo 'foo';
PHP
            ,
            [],
        ];

        yield 'Simple file' => [
            <<<'PHP'
                <?php
                /** file: foo.blade.php, line: 5 */
                echo 'foo';
PHP
            ,
            [
                3 => [
                    'foo.blade.php' => 5,
                ],
            ],
        ];

        yield 'File with multiple lines' => [
            <<<'PHP'
                <?php
                /** file: foo.blade.php, line: 5 */
                echo 'foo';
                /** file: foo.blade.php, line: 55 */
                echo 'bar';
PHP
            ,
            [
                3 => [
                    'foo.blade.php' => 5,
                ],
                5 => [
                    'foo.blade.php' => 55,
                ],
            ],
        ];

        yield 'File with multiple file names' => [
            <<<'PHP'
                <?php
                /** file: foo.blade.php, line: 5 */
                echo 'foo';
                /** file: foo.blade.php, line: 6 */
                echo 'bar';
                /** file: bar.blade.php, line: 55 */
                echo 'baz';
PHP
            ,
            [
                3 => [
                    'foo.blade.php' => 5,
                ],
                5 => [
                    'foo.blade.php' => 6,
                ],
                7 => [
                    'bar.blade.php' => 55,
                ],
            ],
        ];
    }

    /**
     * @return string[]
     */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../../config/extension.neon'];
    }
}
