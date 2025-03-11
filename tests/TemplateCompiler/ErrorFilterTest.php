<?php

declare(strict_types=1);

namespace Bladestan\Tests\TemplateCompiler;

use Bladestan\TemplateCompiler\ErrorFilter;
use PHPStan\Analyser\Error;
use PHPUnit\Framework\TestCase;

final class ErrorFilterTest extends TestCase
{
    public function test(): void
    {
        $errorFilter = new ErrorFilter();
        /** @phpstan-ignore phpstanApi.constructor */
        $ruleError = new Error(
            message: 'Anonymous function has an unused use $foo.',
            file: 'some_file.php',
            identifier: 'closure.unusedUse',
        );

        $filteredErrors = $errorFilter->filterErrors([$ruleError]);
        $this->assertEmpty($filteredErrors);
    }
}
