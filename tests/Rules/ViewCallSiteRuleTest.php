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

        // A controller's public $user property does not reach the view, so the
        // template's required $user is still missing.
        yield [__DIR__ . '/Fixture/view-call-site-controller-property.php', [
            ['Template signed-template requires parameter $user of type \App\Models\User, but it was not provided.', 19],
        ]];

        // Template extending a layout — the merged signature requires the
        // layout's $siteName; the child's string $title narrows ?string.
        yield [__DIR__ . '/Fixture/view-call-site-extends-missing-parent-param.php', [
            ['Template extends-template requires parameter $siteName of type string, but it was not provided.', 11],
        ]];

        // Mailable Content: the view name is recognized positionally and via
        // view:, and with: supplies the data.
        yield [__DIR__ . '/Fixture/view-call-site-mailable-content.php', [
            ['Template signed-template requires parameter $user of type \App\Models\User, but it was not provided.', 14],
        ]];

        // A template extending a nonexistent layout is reported: its merged
        // contract is incomplete, so silence would hide unchecked variables.
        yield [__DIR__ . '/Fixture/view-call-site-extends-missing-layout.php', [
            ['Template extends-missing-layout.blade.php extends layouts.does-not-exist, which does not exist.', 11],
        ]];

        // A child with no signature of its own still inherits its layout's
        // contract: the call site provides $title but not the layout-required
        // $siteName.
        yield [__DIR__ . '/Fixture/view-call-site-unsigned-extends.php', [
            [
                'Template unsigned-extends-template requires parameter $siteName of type string, but it was not provided.',
                12,
            ],
        ]];

        // An unparsable type in a signature is reported as a localized error
        // and does not abort the run: the wrong type for $title is still caught.
        yield [__DIR__ . '/Fixture/view-call-site-invalid-signature-type.php', [
            [
                'Template invalid-signature-type declares $items as \Illuminate\Pagination\LengthAwarePaginator<int, TModel (class \App\Foo, argument)>, which is not a valid PHPDoc type.',
                16,
            ],
            ['Template invalid-signature-type expects parameter $title of type string, but int given.', 16],
        ]];

        // Scope forwarding (compiled @include): variables in the surrounding
        // scope satisfy the signature, with their types still validated.
        yield [__DIR__ . '/Fixture/view-call-site-scope-forwarding.php', [
            [
                'Template signed-template expects parameter $title of type string, but int given by the surrounding scope.',
                26,
            ],
            [
                'Template signed-template requires parameter $user of type \App\Models\User, but it was not provided.',
                30,
            ],
            ['Template signed-template requires parameter $title of type string, but it was not provided.', 36],
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
