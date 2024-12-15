<?php

declare(strict_types=1);

namespace Bladestan\TemplateCompiler;

use PHPStan\Analyser\Error;

/**
 * @see \Bladestan\Tests\TemplateCompiler\ErrorFilterTest
 */
final class ErrorFilter
{
    /**
     * @var string[]
     */
    private const ERRORS_TO_IGNORE_REGEXES = [
        '#Anonymous function has an unused use (.*?)#',
        '#Variable \$__env might not be defined#',
        // e() function for render can accept more values than strings
        '#Parameter \#1 \$value of function e expects BackedEnum\|Illuminate(.*?)\|string\|null, (int|float) given#',
        // forms errors, given optionally
        '#Variable \$errors might not be defined#',
        '#Offset 1 on array{\'(.*?)\'} on left side of \?\? does not exist#',
        '#Undefined variable\: \$errors#',
    ];

    /**
     * @param Error[] $ruleErrors
     * @return Error[]
     */
    public function filterErrors(array $ruleErrors): array
    {
        foreach ($ruleErrors as $key => $ruleError) {
            if (! $this->isAllowedErrorMessage($ruleError->getMessage())) {
                continue;
            }

            unset($ruleErrors[$key]);
        }

        return $ruleErrors;
    }

    private function isAllowedErrorMessage(string $errorMessage): bool
    {
        foreach (self::ERRORS_TO_IGNORE_REGEXES as $errorToIgnoreRegex) {
            if (preg_match($errorToIgnoreRegex, $errorMessage)) {
                return true;
            }
        }

        return false;
    }
}
