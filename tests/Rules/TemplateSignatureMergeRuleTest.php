<?php

declare(strict_types=1);

namespace Bladestan\Tests\Rules;

use Bladestan\Compiler\SignatureMerger;
use Bladestan\Rules\TemplateSignatureMergeRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<TemplateSignatureMergeRule>
 */
final class TemplateSignatureMergeRuleTest extends RuleTestCase
{
    private const SKELETON_VIEWS = __DIR__ . '/../skeleton/resources/views';

    public function testReportsUnresolvableExtendsParentOncePerTemplate(): void
    {
        $this->analyse([self::SKELETON_VIEWS . '/extends-missing-layout.blade.php'], [
            [
                'Template extends-missing-layout.blade.php extends layouts.does-not-exist, which does not exist.',
                1,
            ],
        ]);
    }

    public function testReportsCovarianceWideningOncePerTemplate(): void
    {
        $this->analyse([self::SKELETON_VIEWS . '/widen-extends.blade.php'], [
            [
                'Template widen-extends.blade.php declares $siteName as string|int, but extended template '
                    . 'base-layout.blade.php declares it as string. Child templates may narrow types but not widen them.',
                1,
            ],
        ]);
    }

    public function testTemplateWithACleanSignatureProducesNothing(): void
    {
        $this->analyse([self::SKELETON_VIEWS . '/extends-template.blade.php'], []);
    }

    public function testPhpFilesAreIgnored(): void
    {
        // A hand-written PHP file is never a template, so it must never be run
        // through signature merging.
        $this->analyse([__DIR__ . '/Fixture/view-call-site-extends-missing-layout.php'], []);
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
        return new TemplateSignatureMergeRule(self::getContainer()->getByType(SignatureMerger::class));
    }
}
