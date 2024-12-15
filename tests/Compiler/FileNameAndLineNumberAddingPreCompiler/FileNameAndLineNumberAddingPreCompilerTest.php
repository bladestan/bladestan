<?php

declare(strict_types=1);

namespace Bladestan\Tests\Compiler\FileNameAndLineNumberAddingPreCompiler;

use Bladestan\Compiler\FileNameAndLineNumberAddingPreCompiler;
use Bladestan\Configuration\Configuration;
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
            ->completeLineCommentsToBladeContents('/var/www/resources/views/foo.blade.php', $inputBladeContents);
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
        yield ['/var/www/resources/views/foo.blade.php', '/** file: foo.blade.php, line: 1 */{{ $foo }}'];

        yield ['/var/www/resources/views/bar.blade.php', '/** file: bar.blade.php, line: 1 */{{ $foo }}'];

        yield [
            '/var/www/resources/views/users/index.blade.php',
            '/** file: users/index.blade.php, line: 1 */{{ $foo }}',
        ];
    }

    public function testFindCorrectTemplatePath(): void
    {
        $configuration = new Configuration([
            Configuration::TEMPLATE_PATHS => ['resources/views', 'foo/bar'],
        ]);

        $fileNameAndLineNumberAddingPreCompiler = new FileNameAndLineNumberAddingPreCompiler($configuration);

        $this->assertSame(
            '/** file: users/index.blade.php, line: 1 */{{ $foo }}',
            $fileNameAndLineNumberAddingPreCompiler
                ->completeLineCommentsToBladeContents('/var/www/foo/bar/users/index.blade.php', '{{ $foo }}')
        );
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../../../config/extension.neon'];
    }
}
