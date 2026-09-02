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

        // A template is analysed as itself: PHPStan is handed the compiled form
        // of the .blade.php file it discovered, and the error comes back on the
        // template line the @include is written on, with no remapping step in
        // between.
        yield [__DIR__ . '/../skeleton/resources/views/include-with-wrong-type.blade.php', [
            ['Template signed-template expects parameter $title of type string, but int given.', 10],
        ]];

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

        // A mixed value passed for a typed variable follows PHPStan's own
        // acceptance rules: below checkExplicitMixed (level 9) it is accepted,
        // just as it would be for a normal function argument.
        yield [__DIR__ . '/Fixture/view-call-site-mixed-param.php', []];

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

        // View factory and mailable method forms (make/first/renderWhen/
        // renderUnless/markdown) each resolve the template and report the
        // missing $user; renderEach forwards $key and the iterator var, which
        // satisfy render-each-item's signature.
        yield [__DIR__ . '/Fixture/view-call-site-view-methods.php', [
            ['Template signed-template requires parameter $user of type \App\Models\User, but it was not provided.', 14],
            ['Template signed-template requires parameter $user of type \App\Models\User, but it was not provided.', 20],
            ['Template signed-template requires parameter $user of type \App\Models\User, but it was not provided.', 26],
            ['Template signed-template requires parameter $user of type \App\Models\User, but it was not provided.', 32],
            ['Template signed-template requires parameter $user of type \App\Models\User, but it was not provided.', 45],
        ]];

        // A template extending a nonexistent layout does not break call-site
        // validation: the child's own $title is satisfied here, and the broken
        // @extends is reported once against the template by
        // TemplateSignatureMergeRule, not at every call site.
        yield [__DIR__ . '/Fixture/view-call-site-extends-missing-layout.php', []];

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

        // A data argument whose array shape can't be statically determined
        // (a typed parameter, array_merge()) must not report every signature
        // variable as missing — the opaque value may supply any of them.
        yield [__DIR__ . '/Fixture/view-call-site-unresolvable-data.php', []];

        // An optional array-shape key (user?: User) may be absent at runtime,
        // so it does not satisfy the required $user; $title is still accepted.
        yield [__DIR__ . '/Fixture/view-call-site-optional-key.php', [
            ['Template signed-template requires parameter $user of type \App\Models\User, but it was not provided.', 19],
        ]];

        // Data passed through ->with() that the visitor can't read statically
        // (compact(), an opaque array, a dynamic key) marks the site unresolved
        // rather than "nothing passed", so no signature variable is reported missing.
        yield [__DIR__ . '/Fixture/view-call-site-with-unresolved.php', []];

        // A ->with() chain (both the key/value and the array form) provides the
        // required variables; a wrong type through ->with() is still reported.
        yield [__DIR__ . '/Fixture/view-call-site-with-chain.php', [
            ['Template signed-template expects parameter $title of type string, but int given.', 24],
        ]];

        // A ->with() chain reached through a variable assigned from view() is
        // resolved the same as a direct chain.
        yield [__DIR__ . '/Fixture/view-call-site-with-variable-chain.php', []];

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
