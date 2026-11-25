<?php

declare(strict_types=1);

namespace Bladestan\Tests\Laravel;

use Bladestan\Laravel\BladeEnvironmentDescriber;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use PHPUnit\Framework\TestCase;

final class BladeEnvironmentDescriberTest extends TestCase
{
    public function testDescriptionIsStable(): void
    {
        $bladeCompiler = $this->bladeCompiler();
        $bladeCompiler->directive('money', static fn (string $expression): string => "<?php echo {$expression}; ?>");

        $bladeEnvironmentDescriber = new BladeEnvironmentDescriber();

        $this->assertSame(
            $bladeEnvironmentDescriber->describeCompiler($bladeCompiler),
            $bladeEnvironmentDescriber->describeCompiler($bladeCompiler),
        );
    }

    public function testAddingADirectiveChangesTheDescription(): void
    {
        $bladeCompiler = $this->bladeCompiler();
        $bladeEnvironmentDescriber = new BladeEnvironmentDescriber();
        $before = $bladeEnvironmentDescriber->describeCompiler($bladeCompiler);

        $bladeCompiler->directive('money', static fn (string $expression): string => "<?php echo {$expression}; ?>");

        $this->assertNotSame($before, $bladeEnvironmentDescriber->describeCompiler($bladeCompiler));
    }

    public function testEditingADirectiveBodyChangesTheDescription(): void
    {
        $bladeCompiler = $this->bladeCompiler();
        $bladeCompiler->directive('money', static fn (string $expression): string => "<?php echo {$expression}; ?>");

        $second = $this->bladeCompiler();
        $second->directive('money', static fn (string $expression): string => "<?php echo (int) {$expression}; ?>");

        $bladeEnvironmentDescriber = new BladeEnvironmentDescriber();

        $this->assertNotSame(
            $bladeEnvironmentDescriber->describeCompiler($bladeCompiler),
            $bladeEnvironmentDescriber->describeCompiler($second),
        );
    }

    public function testRegisteringAComponentNamespaceChangesTheDescription(): void
    {
        $bladeCompiler = $this->bladeCompiler();
        $bladeEnvironmentDescriber = new BladeEnvironmentDescriber();
        $before = $bladeEnvironmentDescriber->describeCompiler($bladeCompiler);

        $bladeCompiler->componentNamespace('App\\View\\Shop', 'shop');

        $this->assertNotSame($before, $bladeEnvironmentDescriber->describeCompiler($bladeCompiler));
    }

    private function bladeCompiler(): BladeCompiler
    {
        return new BladeCompiler(new Filesystem(), sys_get_temp_dir());
    }
}
