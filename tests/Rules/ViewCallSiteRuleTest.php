<?php

declare(strict_types=1);

namespace Bladestan\Tests\Rules;

use Bladestan\Rules\ViewCallSiteRule;
use Iterator;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @extends RuleTestCase<ViewCallSiteRule>
 */
final class ViewCallSiteRuleTest extends RuleTestCase
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
        // Correct call site — all parameters provided with matching types
        yield [__DIR__ . '/Fixture/view-call-site-correct.php', []];

        // Wrong type for $title (int instead of string)
        yield [__DIR__ . '/Fixture/view-call-site-wrong-type.php', [
            ['Template signed-template expects parameter $title of type string, but int given.', 9],
        ]];

        // Missing required parameter $user
        yield [__DIR__ . '/Fixture/view-call-site-missing-param.php', [
            ['Template signed-template requires parameter $user of type \App\Models\User, but it was not provided.', 9],
        ]];

        // Template without signature — no errors from ViewCallSiteRule
        yield [__DIR__ . '/Fixture/view-call-site-no-signature.php', []];

        // Template extending a layout — the merged signature requires the
        // layout's $siteName; the child's string $title narrows ?string.
        yield [__DIR__ . '/Fixture/view-call-site-extends-missing-parent-param.php', [
            ['Template extends-template requires parameter $siteName of type string, but it was not provided.', 11],
        ]];
    }

    /**
     * @return list<string>
     */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/config/configured_extension.neon'];
    }

    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(ViewCallSiteRule::class);
    }
}
