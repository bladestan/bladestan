<?php

declare(strict_types=1);

namespace Bladestan\Tests\Compiler;

use Bladestan\Compiler\BladeToPHPCompiler;
use PHPStan\Testing\PHPStanTestCase;

final class CompileStandaloneTest extends PHPStanTestCase
{
    private BladeToPHPCompiler $bladeToPHPCompiler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bladeToPHPCompiler = self::getContainer()->getByType(BladeToPHPCompiler::class);
    }

    public function testEmitsSignatureVarsAndStripsSignatureBlock(): void
    {
        $compiled = $this->compileView('signed-template');

        $this->assertStringContainsString('/** @var string $title */', $compiled);
        $this->assertStringContainsString('/** @var \App\Models\User $user */', $compiled);
        $this->assertStringContainsString('echo e($title);', $compiled);
        $this->assertStringNotContainsString('@bladestan-signature', $compiled);
    }

    public function testImplicitFirstDocblockSignatureIsUsedAndStripped(): void
    {
        $compiled = $this->compileView('implicit-signed-template');

        $this->assertStringContainsString('/** @var string $name */', $compiled);
        $this->assertStringContainsString('/** @var int $age */', $compiled);
        // The original docblock is stripped — each @var appears exactly once
        $this->assertSame(1, substr_count($compiled, '@var string $name'));
    }

    public function testIncludeBecomesViewCallSiteInsteadOfInlining(): void
    {
        $compiled = $this->compileView('file_with_include');

        // Blade's scope forwarding is preserved as view()'s $mergeData
        // parameter, so the rule can let scope variables satisfy the
        // partial's signature exactly as they do at runtime.
        $this->assertStringContainsString(
            "view('included_view', ['foo' => 10, 'bar' => \$foo . 'bar'], get_defined_vars());",
            $compiled
        );
        // No inlined closure from the old recursive pipeline
        $this->assertStringNotContainsString('function () {', $compiled);
    }

    public function testExtendsIsStrippedNotCompiledAsCallSite(): void
    {
        $compiled = $this->compileView('extends-template');

        // @extends is enforced via signature merging at the child's call
        // sites, never compiled into a view() call that would false-positive
        // on the layout's required parameters.
        $this->assertStringNotContainsString("view('layouts.base-layout'", $compiled);
        $this->assertStringNotContainsString('$__env->make', $compiled);
        // Section content is still analyzed
        $this->assertStringContainsString('echo e($user->email);', $compiled);
    }

    public function testComponentBodyGetsBladeInjectedScope(): void
    {
        $compiled = $this->compileView('components.alert');

        // $slot, $componentName, and each @props variable are declared so the
        // body does not report them as undefined.
        $this->assertStringContainsString('/** @var Illuminate\View\ComponentSlot $slot */', $compiled);
        $this->assertStringContainsString('/** @var string $componentName */', $compiled);
        $this->assertStringContainsString('/** @var string $type */', $compiled);
        $this->assertStringContainsString('/** @var mixed $title */', $compiled);
        $this->assertStringContainsString('/** @var bool $dismissible */', $compiled);

        // The compiled @props block already defines $attributes, so it is not
        // re-declared as an @var.
        $this->assertStringNotContainsString('@var Illuminate\View\ComponentAttributeBag $attributes', $compiled);
    }

    public function testNonComponentTemplateGetsNoComponentScope(): void
    {
        $compiled = $this->compileView('signed-template');

        $this->assertStringNotContainsString('$slot', $compiled);
        $this->assertStringNotContainsString('$componentName', $compiled);
    }

    /**
     * @return list<string>
     */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../../config/extension.neon'];
    }

    private function compileView(string $viewName): string
    {
        $filePath = __DIR__ . '/../skeleton/resources/views/' . str_replace('.', '/', $viewName) . '.blade.php';
        $this->assertFileExists($filePath);

        return $this->bladeToPHPCompiler->compileStandalone(realpath($filePath) ?: $filePath, $viewName)
            ->phpFileContents;
    }
}
