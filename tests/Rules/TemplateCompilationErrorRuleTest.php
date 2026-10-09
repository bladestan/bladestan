<?php

declare(strict_types=1);

namespace Bladestan\Tests\Rules;

use Bladestan\Rules\TemplateCompilationErrorRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<TemplateCompilationErrorRule>
 */
final class TemplateCompilationErrorRuleTest extends RuleTestCase
{
    private const SKELETON_VIEWS = __DIR__ . '/../skeleton/resources/views';

    public function testReportsATemplateThatCannotBeCompiled(): void
    {
        // A template Blade itself cannot compile has no statements to analyse,
        // so it would read as clean without this rule.
        $this->analyse([self::SKELETON_VIEWS . '/compile-error.blade.php'], [
            ['View [compile-error.blade.php] contains syntax errors.', 1],
        ]);
    }

    public function testReportsEveryFailureCollectedForOneTemplate(): void
    {
        $this->analyse([self::SKELETON_VIEWS . '/duplicate-signature.blade.php'], [
            [
                'Multiple @bladestan-signature docblocks found; a template may declare only one. '
                    . 'The first is used and the rest are ignored. Remove the extra blocks.',
                1,
            ],
        ]);
    }

    public function testATemplateThatCompilesCleanlyProducesNothing(): void
    {
        $this->analyse([self::SKELETON_VIEWS . '/signed-template.blade.php'], []);
    }

    public function testPhpFilesAreIgnored(): void
    {
        $this->analyse([__DIR__ . '/Fixture/view-call-site-correct.php'], []);
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
        return new TemplateCompilationErrorRule();
    }
}
