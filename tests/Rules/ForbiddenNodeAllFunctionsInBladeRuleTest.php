<?php

declare(strict_types=1);

namespace Bladestan\Tests\Rules;

use Bladestan\Rules\BladeRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<Rule>
 */
final class ForbiddenNodeAllFunctionsInBladeRuleTest extends RuleTestCase
{
    public function test(): void
    {
        $this->analyse([__DIR__ . '/Fixture/forbidden-functions-no-echo.php'], [
            ['Forbidden code: function json_encode() is not allowed.', 9],
            ['Forbidden code: function strtoupper() is not allowed.', 9],
        ]);
    }

    /**
     * @return list<string>
     */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/config/configured_forbidden_all_functions_extension.neon'];
    }

    /**
     * @return BladeRule
     */
    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(BladeRule::class);
    }
}
