<?php

declare(strict_types=1);

namespace Bladestan\Tests\Rules;

use Bladestan\Rules\BladeRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<Rule>
 */
final class ForbiddenNodeDefaultsInBladeRuleTest extends RuleTestCase
{
    public function test(): void
    {
        $this->analyse([__DIR__ . '/Fixture/forbidden-defaults.php'], [
            ['Forbidden code: Expr_Eval is not allowed.', 9],
            ['Forbidden code: Expr_Print is not allowed.', 9],
            ['Forbidden code: function dd() is not allowed.', 9],
        ]);
    }

    /**
     * @return list<string>
     */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/config/configured_extension.neon'];
    }

    /**
     * @return BladeRule
     */
    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(BladeRule::class);
    }
}
