<?php

declare(strict_types=1);

namespace Bladestan\Tests\Bootstrap;

use Bladestan\Bootstrap\CompiledPathDetector;
use PHPUnit\Framework\TestCase;

final class CompiledPathDetectorTest extends TestCase
{
    private CompiledPathDetector $compiledPathDetector;

    private string $projectRoot;

    protected function setUp(): void
    {
        $this->compiledPathDetector = new CompiledPathDetector();

        // A real directory, so the realpath-based canonicalization has
        // something to resolve. The compiled directory below deliberately does
        // not exist: that is what a first run looks like.
        $this->projectRoot = (string) realpath(__DIR__ . '/../..');
    }

    public function testCompiledDirectoryListedDirectly(): void
    {
        $this->assertTrue($this->compiledPathDetector->isAnalysed(
            $this->projectRoot . '/.bladestan',
            [$this->projectRoot . '/src', $this->projectRoot . '/.bladestan'],
        ));
    }

    public function testCompiledDirectoryNestedInsideAnAnalysedPath(): void
    {
        // PHPStan's file discovery walks into subdirectories, so compiled output
        // under an analysed directory is analysed just as directly.
        $this->assertTrue($this->compiledPathDetector->isAnalysed(
            $this->projectRoot . '/build/bladestan',
            [$this->projectRoot . '/build'],
        ));
    }

    public function testCompiledDirectoryAbsentFromTheAnalysedPaths(): void
    {
        $this->assertFalse($this->compiledPathDetector->isAnalysed(
            $this->projectRoot . '/.bladestan',
            [$this->projectRoot . '/src'],
        ));
    }

    public function testTrailingSlashesAndDotSegmentsCompareEqual(): void
    {
        $this->assertTrue($this->compiledPathDetector->isAnalysed(
            $this->projectRoot . '/.bladestan/',
            [$this->projectRoot . '/src/../.bladestan'],
        ));
    }

    public function testSiblingDirectoryWithASharedPrefixIsNotAMatch(): void
    {
        $this->assertFalse($this->compiledPathDetector->isAnalysed(
            $this->projectRoot . '/.bladestan-other/compiled',
            [$this->projectRoot . '/.bladestan'],
        ));
    }

    public function testSameNameInAnotherDirectoryIsReportedAsDivergent(): void
    {
        $divergent = $this->compiledPathDetector->divergentPath(
            $this->projectRoot . '/.bladestan',
            [$this->projectRoot . '/src', $this->projectRoot . '/tests/.bladestan'],
        );

        $this->assertSame($this->projectRoot . '/tests/.bladestan', $divergent);
    }

    public function testNoDivergenceWhenTheCompiledDirectoryIsActuallyAnalysed(): void
    {
        // The same name appearing elsewhere is only a divergence when the real
        // compiled directory is missing from the analysed paths.
        $this->assertNull($this->compiledPathDetector->divergentPath(
            $this->projectRoot . '/.bladestan',
            [$this->projectRoot . '/tests/.bladestan', $this->projectRoot . '/.bladestan'],
        ));
    }

    public function testNoDivergenceWithoutASameNamedPath(): void
    {
        $this->assertNull($this->compiledPathDetector->divergentPath(
            $this->projectRoot . '/.bladestan',
            [$this->projectRoot . '/src'],
        ));
    }
}
