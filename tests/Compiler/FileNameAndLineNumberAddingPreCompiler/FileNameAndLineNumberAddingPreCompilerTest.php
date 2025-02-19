<?php

declare(strict_types=1);

namespace Bladestan\Tests\Compiler\FileNameAndLineNumberAddingPreCompiler;

use Bladestan\Compiler\FileNameAndLineNumberAddingPreCompiler;
use Bladestan\Tests\TestUtils;
use Iterator;
use PHPStan\Testing\PHPStanTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class FileNameAndLineNumberAddingPreCompilerTest extends PHPStanTestCase
{
    private FileNameAndLineNumberAddingPreCompiler $fileNameAndLineNumberAddingPreCompiler;

    protected function setUp(): void
    {
        //$this->templatePaths = ['resources/views'];

        parent::setUp();

        $this->fileNameAndLineNumberAddingPreCompiler = self::getContainer()->getByType(
            FileNameAndLineNumberAddingPreCompiler::class
        );
    }

    #[DataProvider('fixtureProvider')]
    public function testUpdateLineNumbers(string $filePath): void
    {
        [$inputBladeContents, $expectedPhpCompiledContent] = TestUtils::splitFixture($filePath);

        $phpFileContent = $this->fileNameAndLineNumberAddingPreCompiler
            ->completeLineCommentsToBladeContents(
                __DIR__ . '/../../skeleton/resources/views/foo.blade.php',
                $inputBladeContents
            );
        $this->assertSame($expectedPhpCompiledContent, $phpFileContent);
    }

    public static function fixtureProvider(): Iterator
    {
        return TestUtils::yieldDirectory(__DIR__ . '/Fixture');
    }

    #[DataProvider('provideData')]
    public function testChangeFileForSameTemplate(string $fileName, string $expectedCompiledComments): void
    {
        $compiledComments = $this->fileNameAndLineNumberAddingPreCompiler
            ->completeLineCommentsToBladeContents($fileName, '{{ $foo }}');
        $this->assertSame($expectedCompiledComments, $compiledComments);
    }

    public static function provideData(): Iterator
    {
        yield [
            __DIR__ . '/../../skeleton/resources/views/foo.blade.php',
            '/** file: foo.blade.php, line: 1 */{{ $foo }}',
        ];

        yield [
            __DIR__ . '/../../skeleton/resources/views/bar.blade.php',
            '/** file: bar.blade.php, line: 1 */{{ $foo }}',
        ];

        yield [
            (realpath(__DIR__ . '/../..') ?: '') . '/skeleton/resources/views/users/index.blade.php',
            '/** file: users/index.blade.php, line: 1 */{{ $foo }}',
        ];
    }

    public function testFindCorrectTemplatePath(): void
    {
        $fileNameAndLineNumberAddingPreCompiler = new FileNameAndLineNumberAddingPreCompiler();

        $this->assertSame(
            '/** file: users/index.blade.php, line: 1 */{{ $foo }}',
            $fileNameAndLineNumberAddingPreCompiler
                ->completeLineCommentsToBladeContents(
                    (realpath(__DIR__ . '/../..') ?: '') . '/skeleton/resources/views/users/index.blade.php',
                    '{{ $foo }}'
                )
        );
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../../../config/extension.neon'];
    }
}
