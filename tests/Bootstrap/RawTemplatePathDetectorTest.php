<?php

declare(strict_types=1);

namespace Bladestan\Tests\Bootstrap;

use Bladestan\Bootstrap\RawTemplatePathDetector;
use PHPUnit\Framework\TestCase;

final class RawTemplatePathDetectorTest extends TestCase
{
    private RawTemplatePathDetector $rawTemplatePathDetector;

    protected function setUp(): void
    {
        $this->rawTemplatePathDetector = new RawTemplatePathDetector();
    }

    public function testAnalysedViewDirectoryContainingTemplatesConflicts(): void
    {
        $conflicts = $this->rawTemplatePathDetector->conflictingPaths(
            ['/project/app', '/project/resources/views'],
            ['/project/resources/views/welcome.blade.php', '/project/resources/views/layouts/app.blade.php'],
        );

        $this->assertSame(['/project/resources/views'], $conflicts);
    }

    public function testCompiledOutputDirectoryDoesNotConflict(): void
    {
        // Compiled output holds `.php` files, never `.blade.php`, so `.bladestan`
        // is never reported even though it sits alongside the templates.
        $conflicts = $this->rawTemplatePathDetector->conflictingPaths(
            ['/project/app', '/project/.bladestan'],
            ['/project/resources/views/welcome.blade.php'],
        );

        $this->assertSame([], $conflicts);
    }

    public function testExactTemplateFilePathConflicts(): void
    {
        $conflicts = $this->rawTemplatePathDetector->conflictingPaths(
            ['/project/resources/views/welcome.blade.php'],
            ['/project/resources/views/welcome.blade.php'],
        );

        $this->assertSame(['/project/resources/views/welcome.blade.php'], $conflicts);
    }

    public function testSiblingDirectoryWithSharedPrefixDoesNotConflict(): void
    {
        // `/project/resources/views2` must not be treated as inside
        // `/project/resources/views`: the prefix check is directory-aware.
        $conflicts = $this->rawTemplatePathDetector->conflictingPaths(
            ['/project/resources/views'],
            ['/project/resources/views2/welcome.blade.php'],
        );

        $this->assertSame([], $conflicts);
    }

    public function testNoTemplatesMeansNoConflicts(): void
    {
        $conflicts = $this->rawTemplatePathDetector->conflictingPaths(
            ['/project/app', '/project/.bladestan'],
            [],
        );

        $this->assertSame([], $conflicts);
    }
}
