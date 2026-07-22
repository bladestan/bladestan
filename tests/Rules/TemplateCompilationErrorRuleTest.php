<?php

declare(strict_types=1);

namespace Bladestan\Tests\Rules;

use Bladestan\Rules\TemplateCompilationErrorRule;
use Iterator;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @extends RuleTestCase<TemplateCompilationErrorRule>
 */
final class TemplateCompilationErrorRuleTest extends RuleTestCase
{
    /**
     * @param list<array{0: string, 1: int, 2?: string|null}> $expectedErrorsWithLines
     */
    #[DataProvider('provideData')]
    public function testRule(string $analysedFile, array $expectedErrorsWithLines): void
    {
        $this->analyse([$analysedFile], $expectedErrorsWithLines);
    }

    public static function provideData(): Iterator
    {
        // A parse failure recorded during compilation is surfaced instead of
        // the template dropping out of analysis silently.
        yield [__DIR__ . '/Fixture/compiled/parse-error.php', [
            ['View [broken.blade.php] contains syntax errors.', 1],
        ]];

        // Every recorded marker is reported, including a multi-line message.
        yield [__DIR__ . '/Fixture/compiled/multiple-errors.php', [
            ['View [partials.missing] not found.', 1],
            ["Composer for [dashboard] threw:\nboom", 1],
        ]];

        // A compiled file with no error markers produces nothing.
        yield [__DIR__ . '/Fixture/compiled/clean.php', []];
    }

    protected function getRule(): Rule
    {
        // Construct directly with the fixture directory: %currentWorkingDirectory%
        // resolves to the PHPStan phar during tests, so the container-wired path
        // could never match the fixtures on disk.
        return new TemplateCompilationErrorRule(__DIR__ . '/Fixture/compiled');
    }
}
