<?php

declare(strict_types=1);

namespace Bladestan\Tests\Blade;

use Bladestan\Blade\TemplateLineMap;
use PHPUnit\Framework\TestCase;

final class TemplateLineMapTest extends TestCase
{
    public function testAnchoredLinesMapToTheirTemplateLine(): void
    {
        $templateLineMap = TemplateLineMap::fromResolvedLines([
            10 => [
                'welcome.blade.php' => 3,
            ],
            12 => [
                'welcome.blade.php' => 7,
            ],
        ]);

        $this->assertSame(3, $templateLineMap->templateLine(10));
        $this->assertSame(7, $templateLineMap->templateLine(12));
    }

    public function testALineBetweenAnchorsBelongsToTheAnchorAboveIt(): void
    {
        // A directive that compiles to several statements leaves every line
        // after the first without an anchor of its own; they all came from the
        // template line that produced them.
        $templateLineMap = TemplateLineMap::fromResolvedLines([
            10 => [
                'welcome.blade.php' => 3,
            ],
            14 => [
                'welcome.blade.php' => 7,
            ],
        ]);

        $this->assertSame(3, $templateLineMap->templateLine(11));
        $this->assertSame(3, $templateLineMap->templateLine(13));
        $this->assertSame(7, $templateLineMap->templateLine(99));
    }

    public function testThePreambleAnchorsToTheFirstTemplateLine(): void
    {
        // Everything above the first anchor is generated: the @var annotations
        // for the signature, composer data, and shared variables. Reporting
        // those at their compiled line would point at unrelated template text.
        $templateLineMap = TemplateLineMap::fromResolvedLines([
            10 => [
                'welcome.blade.php' => 3,
            ],
        ]);

        $this->assertSame(1, $templateLineMap->templateLine(1));
        $this->assertSame(1, $templateLineMap->templateLine(9));
    }

    public function testATemplateWithoutAnchorsMapsEverythingToLineOne(): void
    {
        $templateLineMap = TemplateLineMap::fromResolvedLines([]);

        $this->assertSame(1, $templateLineMap->templateLine(1));
        $this->assertSame(1, $templateLineMap->templateLine(42));
    }

    public function testAnchorsOutOfOrderAreStillResolvedByLine(): void
    {
        $templateLineMap = TemplateLineMap::fromResolvedLines([
            14 => [
                'welcome.blade.php' => 7,
            ],
            10 => [
                'welcome.blade.php' => 3,
            ],
        ]);

        $this->assertSame(3, $templateLineMap->templateLine(11));
    }
}
