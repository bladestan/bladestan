<?php

declare(strict_types=1);

namespace TomasVotruba\Bladestan\Tests\TemplateCompiler;

use PHPStan\Analyser\Error;
use PHPUnit\Framework\TestCase;
use TomasVotruba\Bladestan\TemplateCompiler\ErrorFilter;

final class ErrorFilterTest extends TestCase
{
    public function test(): void
    {
        $errorFilter = new ErrorFilter();
        /** @phpstan-ignore phpstanApi.constructor */
        $ruleError = new Error('Variable $__env might not be defined', 'some_file.php');

        $filteredErrors = $errorFilter->filterErrors([$ruleError]);
        $this->assertEmpty($filteredErrors);
    }
}
