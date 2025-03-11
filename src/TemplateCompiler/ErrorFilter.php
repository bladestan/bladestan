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
     * @param Error[] $ruleErrors
     * @return Error[]
     */
    public function filterErrors(array $ruleErrors): array
    {
        foreach ($ruleErrors as $key => $ruleError) {
            if ($this->isAllowedErrorMessage($ruleError)) {
                unset($ruleErrors[$key]);
            }
        }

        return $ruleErrors;
    }

    private function isAllowedErrorMessage(Error $ruleError): bool
    {
        $identifier = $ruleError->getIdentifier();
        if ($identifier === 'closure.unusedUse') {
            // Inlined templates gets all variables forwarded since we don't check what they use beforehand
            return true;
        }

        // forms errors, given optionally
        return $identifier === 'nullCoalesce.offset' && preg_match(
            '/^Offset 1 on array{\'(.*?)\'} on left side of \?\? does not exist\.$/',
            $ruleError->getMessage()
        );
    }
}
