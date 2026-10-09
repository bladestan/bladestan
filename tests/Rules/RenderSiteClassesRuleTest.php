<?php

declare(strict_types=1);

namespace Bladestan\Tests\Rules;

use Bladestan\Rules\ViewCallSiteRule;
use Iterator;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A project that renders through its own wrapper class - `new ViewResponse($name, $data)` -
 * hides the template name from the `view()` call inside it, where it is only a parameter.
 * Declaring the class in `renderSiteClasses` makes each `new` a render site, so the call
 * site is validated against the template's signature like any other.
 *
 * @extends RuleTestCase<ViewCallSiteRule>
 */
final class RenderSiteClassesRuleTest extends RuleTestCase
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
        // The declared class is matched, and its data argument is checked
        // against the signature.
        yield [__DIR__ . '/Fixture/render-site-class-wrong-type.php', [
            ['Template signed-template expects parameter $title of type string, but int given.', 9],
        ]];

        // A subclass renders the same way, so declaring the base covers it.
        yield [__DIR__ . '/Fixture/render-site-class-subclass.php', [
            ['Template signed-template expects parameter $title of type string, but int given.', 9],
        ]];

        // Matching types pass, the same as a plain view() call.
        yield [__DIR__ . '/Fixture/render-site-class-correct.php', []];
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/config/render_site_classes.neon'];
    }

    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(ViewCallSiteRule::class);
    }
}
