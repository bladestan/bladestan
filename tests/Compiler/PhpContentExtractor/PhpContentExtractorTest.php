<?php

declare(strict_types=1);

namespace Bladestan\Tests\Compiler\PhpContentExtractor;

use Bladestan\Compiler\FileNameAndLineNumberAddingPreCompiler;
use Bladestan\PhpParser\NodeVisitor\DeleteInlineHTML;
use Bladestan\PhpParser\SimplePhpParser;
use Bladestan\Tests\TestUtils;
use Illuminate\View\Compilers\BladeCompiler;
use Iterator;
use PhpParser\NodeTraverser;
use PhpParser\PrettyPrinter\Standard;
use PHPStan\Testing\PHPStanTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class PhpContentExtractorTest extends PHPStanTestCase
{
    private SimplePhpParser $simplePhpParser;

    private Standard $printerStandard;

    private BladeCompiler $bladeCompiler;

    private FileNameAndLineNumberAddingPreCompiler $fileNameAndLineNumberAddingPreCompiler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->simplePhpParser = self::getContainer()->getByType(SimplePhpParser::class);
        $this->printerStandard = self::getContainer()->getByType(Standard::class);
        $this->bladeCompiler = self::getContainer()->getByType(BladeCompiler::class);
        $this->fileNameAndLineNumberAddingPreCompiler = self::getContainer()->getByType(
            FileNameAndLineNumberAddingPreCompiler::class
        );
    }

    #[DataProvider('fixtureProvider')]
    public function testExtractPhpContentsFromBladeTemplate(string $filePath): void
    {
        [$inputBladeContents, $expectedPhpContents] = TestUtils::splitFixture($filePath);

        $fileContent = $this->fileNameAndLineNumberAddingPreCompiler
            ->completeLineCommentsToBladeContents(
                '/some-directory-name/resources/views/foo.blade.php',
                $inputBladeContents
            );

        $compiledPhpContents = $this->bladeCompiler->compileString($fileContent);

        $stmts = $this->simplePhpParser->parse($compiledPhpContents);
        $nodeTraverser = new NodeTraverser();
        $nodeTraverser->addVisitor(new DeleteInlineHTML());

        $stmts = $nodeTraverser->traverse($stmts);
        $phpFileContent = $this->printerStandard->prettyPrintFile($stmts) . "\n";

        $this->assertSame($expectedPhpContents, $phpFileContent);
    }

    public static function fixtureProvider(): Iterator
    {
        return TestUtils::yieldDirectory(__DIR__ . '/Fixture');
    }

    /**
     * @return list<string>
     */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../../../config/extension.neon'];
    }
}
