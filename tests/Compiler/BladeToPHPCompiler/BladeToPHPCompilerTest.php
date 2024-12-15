<?php

declare(strict_types=1);

namespace Bladestan\Tests\Compiler\BladeToPHPCompiler;

use Bladestan\Compiler\BladeToPHPCompiler;
use Bladestan\TemplateCompiler\ValueObject\VariableAndType;
use Bladestan\Tests\TestUtils;
use Iterator;
use PHPStan\Testing\PHPStanTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class BladeToPHPCompilerTest extends PHPStanTestCase
{
    /**
     * @var VariableAndType[]
     */
    private array $variables = [];

    private BladeToPHPCompiler $bladeToPHPCompiler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bladeToPHPCompiler = self::getContainer()->getByType(BladeToPHPCompiler::class);

        // Setup the variable names and types that'll be available to all templates
        $this->variables = [];
    }

    #[DataProvider('provideData')]
    public function testCompileAndDecorateTypes(string $filePath): void
    {
        [$inputBladeContents, $expectedPhpContents] = TestUtils::splitFixture($filePath);

        $phpFileContentsWithLineMap = $this->bladeToPHPCompiler->compileContent(
            'foo.blade.php',
            $inputBladeContents,
            $this->variables
        );

        $this->assertSame($expectedPhpContents, $phpFileContentsWithLineMap->getPhpFileContents());
    }

    public static function provideData(): Iterator
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
