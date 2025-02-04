<?php

declare(strict_types=1);

namespace Bladestan\Tests\Rules;

use Bladestan\Rules\BladeRule;
use Iterator;
use PhpParser\Node;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @extends RuleTestCase<Rule>
 */
final class BladeRuleTest extends RuleTestCase
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
        yield [__DIR__ . '/Fixture/arrayable.php', [
            ['Strict comparison using === between 1 and 1 will always evaluate to true.', 10],
            ['Binary operation "+" between string and 10 results in an error.', 10],
            ['Binary operation "+" between string and \'bar\' results in an error.', 10],
            ['Strict comparison using === between 1 and 1 will always evaluate to true.', 13],
            ['Binary operation "+" between string and 10 results in an error.', 13],
            ['Binary operation "+" between string and \'bar\' results in an error.', 13],
        ]];

        yield [__DIR__ . '/Fixture/compact.php', [
            ['Binary operation "+" between string and 10 results in an error.', 10],
            ['Variable $bar might not be defined.', 10],
        ]];

        yield [__DIR__ . '/Fixture/compile-error.php', [
            ['View [compile-error.blade.php] contains syntx errors.', 5],
        ]];

        yield [__DIR__ . '/Fixture/empty.php', []];
        yield [__DIR__ . '/Fixture/once.php', [['Variable $foo might not be defined.', 7]]];

        yield [__DIR__ . '/Fixture/file-with-include.php', [
            ['Binary operation "+" between string and 10 results in an error.', 9],
            ['Binary operation "+" between \'10bar\' and 30 results in an error.', 9],
            ['Binary operation "+" between string and 20 results in an error.', 9],
            ['Variable $bar might not be defined.', 9],
        ]];

        yield [__DIR__ . '/Fixture/file-with-recursive-include.php', [
            ['Binary operation "+" between string and 10 results in an error.', 9],
            ['Binary operation "+" between \'10bar\' and 30 results in an error.', 9],
            ['Undefined variable: $bar', 9],
        ]];

        yield [__DIR__ . '/Fixture/laravel-component-function.php', [
            ['Binary operation "+" between string and 10 results in an error.', 17],
        ]];

        yield [__DIR__ . '/Fixture/laravel-component-method.php', [
            ['Binary operation "+" between string and 10 results in an error.', 17],
        ]];

        yield [__DIR__ . '/Fixture/laravel-mailable-method.php', [
            ['Strict comparison using === between 1 and 1 will always evaluate to true.', 17],
            ['Binary operation "+" between string and 10 results in an error.', 17],
            ['Binary operation "+" between string and \'bar\' results in an error.', 17],
            ['Strict comparison using === between 1 and 1 will always evaluate to true.', 24],
            ['Binary operation "+" between string and 10 results in an error.', 24],
            ['Binary operation "+" between string and \'bar\' results in an error.', 24],
        ]];

        yield [__DIR__ . '/Fixture/laravel-mail_message-method.php', [
            ['Strict comparison using === between 1 and 1 will always evaluate to true.', 16],
            ['Binary operation "+" between string and 10 results in an error.', 16],
            ['Binary operation "+" between string and \'bar\' results in an error.', 16],
        ]];

        yield [__DIR__ . '/Fixture/laravel-response-function.php', [
            ['Strict comparison using === between 1 and 1 will always evaluate to true.', 9],
            ['Binary operation "+" between string and 10 results in an error.', 9],
            ['Binary operation "+" between string and \'bar\' results in an error.', 9],
            ['Strict comparison using === between 1 and 1 will always evaluate to true.', 13],
            ['Variable $foo might not be defined.', 13],
            ['Undefined variable: $foo', 13],
        ]];

        yield [__DIR__ . '/Fixture/laravel-view-facade.php', [
            ['Strict comparison using === between 1 and 1 will always evaluate to true.', 7],
            ['Binary operation "+" between string and 10 results in an error.', 7],
            ['Binary operation "+" between string and \'bar\' results in an error.', 7],
        ]];

        // @todo instead of one huge file with 20 errors, there should be similar errors together, just 2-3 errors per file to make easier debugging and extending
        yield [__DIR__ . '/Fixture/laravel-view-function.php', [
            ['Strict comparison using === between 1 and 1 will always evaluate to true.', 9],
            ['Binary operation "+" between string and 10 results in an error.', 9],
            ['Binary operation "+" between string and \'bar\' results in an error.', 9],
            ['Binary operation "+" between string and 10 results in an error.', 13],
            ['Binary operation "+" between int and \'foo\' results in an error.', 13],
            ['Binary operation "+" between string and 10 results in an error.', 16],
            ['Variable $bar might not be defined.', 16],
            // @todo test breaks without the previous statments, they should not affect each other
            ['Binary operation "+" between string and \'bar\' results in an error.', 18],
            ['If condition is always true.', 18],
            ['Binary operation "+" between string and \'bar\' results in an error.', 18],
            ['Strict comparison using === between 1 and 1 will always evaluate to true.', 26],
            ['Binary operation "+" between string and 10 results in an error.', 26],
            ['Binary operation "+" between string and \'bar\' results in an error.', 26],
        ]];

        yield [__DIR__ . '/Fixture/laravel-view-include.php', [
            ['Binary operation "+" between string and \'bar\' results in an error.', 9],
        ]];

        yield [__DIR__ . '/Fixture/missing-component.php', [['View [missing.component] not found.', 5]]];

        yield [__DIR__ . '/Fixture/missing-include.php', [['View [missing.view] not found.', 5]]];

        yield [__DIR__ . '/Fixture/missing-template.php', [['View [missing.view] not found.', 5]]];

        yield [__DIR__ . '/Fixture/namespaced-view.php', [
            ['Binary operation "+" between string and 6 results in an error.', 9],
        ]];

        yield [__DIR__ . '/Fixture/nested-foreach.php', [['Variable $foos might not be defined.', 7]]];

        yield [__DIR__ . '/Fixture/php-directive-with-comment.php', [
            ['Binary operation "+" between string and 10 results in an error.', 7],
        ]];

        yield [__DIR__ . '/Fixture/skip-form-errors.php', []];

        yield [__DIR__ . '/Fixture/static-content.php', []];

        yield [__DIR__ . '/Fixture/view-factory.php', [
            ['Strict comparison using === between 1 and 1 will always evaluate to true.', 13],
            ['Binary operation "+" between string and 10 results in an error.', 13],
            ['Binary operation "+" between string and \'bar\' results in an error.', 13],
            ['Strict comparison using === between 1 and 1 will always evaluate to true.', 16],
            ['Binary operation "+" between string and 10 results in an error.', 16],
            ['Binary operation "+" between string and \'bar\' results in an error.', 16],
            ['Strict comparison using === between 1 and 1 will always evaluate to true.', 19],
            ['Binary operation "+" between string and 10 results in an error.', 19],
            ['Binary operation "+" between string and \'bar\' results in an error.', 19],
        ]];

        yield [__DIR__ . '/Fixture/view-render-int.php', []];
    }

    /**
     * @return string[]
     */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/config/configured_extension.neon'];
    }

    /**
     * @return Rule<Node>
     */
    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(BladeRule::class);
    }
}
