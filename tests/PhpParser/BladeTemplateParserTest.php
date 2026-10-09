<?php

declare(strict_types=1);

namespace Bladestan\Tests\PhpParser;

use Bladestan\PhpParser\BladeTemplateParser;
use PhpParser\Node\Stmt;
use PHPStan\Testing\PHPStanTestCase;

final class BladeTemplateParserTest extends PHPStanTestCase
{
    private const SKELETON_VIEWS = __DIR__ . '/../skeleton/resources/views';

    /**
     * @return list<string>
     */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../Rules/config/configured_extension.neon'];
    }

    public function testTheParserIsRegisteredInPlaceOfPHPStansOwn(): void
    {
        // Everything else here goes through PHPStan's analysis parser, so this
        // is what makes those assertions statements about the shipped wiring
        // rather than about a hand-built object.
        $this->assertInstanceOf(
            BladeTemplateParser::class,
            self::getContainer()->getService('pathRoutingParser'),
        );
    }

    public function testTemplateStatementsCarryTemplateLineNumbers(): void
    {
        // signed-template.blade.php echoes $title on line 9 and $user->email on
        // line 10, below a seven-line signature block. The compiled PHP puts
        // them elsewhere; what PHPStan is handed must say 9 and 10.
        $statements = $this->parseTemplate('signed-template.blade.php');

        $this->assertSame([9, 10], array_map(
            static fn (Stmt $stmt): int => $stmt->getStartLine(),
            $statements,
        ));
    }

    public function testACleanTemplateRecordsNoCompilationFailure(): void
    {
        $statements = $this->parseTemplate('signed-template.blade.php');

        $this->assertSame([], $statements[0]->getAttribute(BladeTemplateParser::COMPILATION_ERRORS_ATTRIBUTE));
    }

    public function testATemplateThatCannotCompileStillCarriesItsFailure(): void
    {
        // Blade cannot compile this one, so there are no statements to analyse.
        // Without the recorded failure the template would read as clean.
        $statements = $this->parseTemplate('compile-error.blade.php');

        $this->assertSame(
            [[
                'message' => 'View [compile-error.blade.php] contains syntax errors.',
                'identifier' => 'bladestan.parsing',
            ]],
            $statements[0]->getAttribute(BladeTemplateParser::COMPILATION_ERRORS_ATTRIBUTE),
        );
    }

    public function testInlineIgnoreCommentsInATemplateAreDiscarded(): void
    {
        // The rich parser records the compiled lines its ignore comments apply
        // to, and those cannot be translated back: a same-line ignore belongs to
        // the template line at or above it, a next-line ignore to the one below,
        // and the record does not say which it was. Keeping either reading
        // silences a line the comment was not written for, so the record has to
        // go and an inline ignore inside a template does nothing at all.
        $statements = $this->parseTemplate('inline-ignore-comments.blade.php');

        $this->assertSame([], $statements[0]->getAttribute('linesToIgnore'));
        $this->assertSame([], $statements[0]->getAttribute('linesToIgnoreParseErrors'));
    }

    public function testPlainPhpFilesAreLeftToPHPStansOwnRouting(): void
    {
        $statements = self::getParser()->parseFile(__DIR__ . '/../Rules/Fixture/view-call-site-correct.php');

        $this->assertNotSame([], $statements);
        $this->assertNull($statements[0]->getAttribute(BladeTemplateParser::COMPILATION_ERRORS_ATTRIBUTE));
    }

    /**
     * @return list<Stmt>
     */
    private function parseTemplate(string $fileName): array
    {
        $filePath = realpath(self::SKELETON_VIEWS . '/' . $fileName);
        self::assertIsString($filePath);

        $statements = array_values(self::getParser()->parseFile($filePath));
        self::assertNotSame([], $statements);

        return $statements;
    }
}
