<?php

declare(strict_types=1);

namespace Bladestan\Tests\Compiler\PhpContentExtractor;

use Bladestan\Compiler\FileNameAndLineNumberAddingPreCompiler;
use Bladestan\Compiler\PhpContentExtractor;
use Bladestan\Tests\TestUtils;
use Illuminate\View\Compilers\BladeCompiler;
use Iterator;
use PHPStan\Testing\PHPStanTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class PhpContentExtractorTest extends PHPStanTestCase
{
    private PhpContentExtractor $phpContentExtractor;

    private BladeCompiler $bladeCompiler;

    private FileNameAndLineNumberAddingPreCompiler $fileNameAndLineNumberAddingPreCompiler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->phpContentExtractor = self::getContainer()->getByType(PhpContentExtractor::class);
        $this->bladeCompiler = self::getContainer()->getByType(BladeCompiler::class);
        $this->fileNameAndLineNumberAddingPreCompiler = self::getContainer()->getByType(
            FileNameAndLineNumberAddingPreCompiler::class
        );
    }

    #[DataProvider('fixtureProvider')]
    public function testExtactPhpContentsFromBladeTemlate(string $filePath): void
    {
        [$inputBladeContents, $expectedPhpContents] = TestUtils::splitFixture($filePath);

        $fileContent = $this->fileNameAndLineNumberAddingPreCompiler
            ->completeLineCommentsToBladeContents(
                '/some-directory-name/resources/views/foo.blade.php',
                $inputBladeContents
            );

        $compiledPhpContents = $this->bladeCompiler->compileString($fileContent);
        $phpFileContent = $this->phpContentExtractor->extract($compiledPhpContents);

        $this->assertSame($expectedPhpContents, $phpFileContent);
    }

    public static function fixtureProvider(): Iterator
    {
        return TestUtils::yieldDirectory(__DIR__ . '/Fixture');
    }

    /**
     * @return string[]
     */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../../../config/extension.neon'];
    }
}
