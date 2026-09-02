<?php

declare(strict_types=1);

namespace Bladestan\Tests\Bootstrap;

use Bladestan\Bootstrap\UnanalysedTemplateDetector;
use PHPUnit\Framework\TestCase;

final class UnanalysedTemplateDetectorTest extends TestCase
{
    public function testAViewRootNoAnalysedPathReachesIsReported(): void
    {
        $unreached = (new UnanalysedTemplateDetector())->unreachedRoots(
            ['/app/src', '/app/tests'],
            ['/app/resources/views'],
        );

        $this->assertSame(['/app/resources/views'], $unreached);
    }

    public function testAnAnalysedPathThatIsTheViewRootReachesIt(): void
    {
        $unreached = (new UnanalysedTemplateDetector())->unreachedRoots(
            ['/app/src', '/app/resources/views'],
            ['/app/resources/views'],
        );

        $this->assertSame([], $unreached);
    }

    public function testAnAnalysedParentDirectoryReachesTheViewRoot(): void
    {
        $unreached = (new UnanalysedTemplateDetector())->unreachedRoots(
            ['/app/resources'],
            ['/app/resources/views'],
        );

        $this->assertSame([], $unreached);
    }

    public function testAnAnalysedSubdirectoryOfTheViewRootCountsAsDeliberate(): void
    {
        // Analysing only part of a view tree is a scoped run, not a
        // misconfiguration, so it must not be nagged about.
        $unreached = (new UnanalysedTemplateDetector())->unreachedRoots(
            ['/app/resources/views/admin'],
            ['/app/resources/views'],
        );

        $this->assertSame([], $unreached);
    }

    public function testASiblingWithASharedPrefixDoesNotCount(): void
    {
        // "/app/resources/views-backup" starts with the same characters as
        // "/app/resources/views" but is a different directory.
        $unreached = (new UnanalysedTemplateDetector())->unreachedRoots(
            ['/app/resources/views-backup'],
            ['/app/resources/views'],
        );

        $this->assertSame(['/app/resources/views'], $unreached);
    }

    public function testEachUnreachedRootIsReportedSeparately(): void
    {
        $unreached = (new UnanalysedTemplateDetector())->unreachedRoots(
            ['/app/resources/views'],
            ['/app/resources/views', '/app/modules/shop/views'],
        );

        $this->assertSame(['/app/modules/shop/views'], $unreached);
    }
}
