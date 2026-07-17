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

    public function testIncludeFirstBecomesViewCallSiteForTheFallbackCandidate(): void
    {
        $compiled = $this->compileView('first_include');

        // @includeFirst renders the first candidate that exists; it is validated
        // against the last (the guaranteed fallback), with the same scope
        // forwarding as a plain @include so scope variables satisfy its signature.
        $this->assertStringContainsString(
            "view('included_view', ['foo' => 10, 'bar' => 'baz'], get_defined_vars());",
            $compiled
        );
        // The optional override ahead of the fallback is not turned into a call
        // site of its own, so its signature never false-positives.
        $this->assertStringNotContainsString("view('partials.override'", $compiled);
        $this->assertStringNotContainsString('$__env->first', $compiled);
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
        $this->assertStringContainsString('/** @var \Illuminate\View\ComponentSlot $slot */', $compiled);
        $this->assertStringContainsString('/** @var string $componentName */', $compiled);
        $this->assertStringContainsString('/** @var string $type */', $compiled);
        $this->assertStringContainsString('/** @var mixed $title */', $compiled);
        $this->assertStringContainsString('/** @var bool $dismissible */', $compiled);

        // $attributes is declared even though @props is present, so the body
        // stays typed no matter how Blade compiled the @props block.
        $this->assertStringContainsString('/** @var \Illuminate\View\ComponentAttributeBag $attributes */', $compiled);
    }

    public function testPropsComponentWithSignatureKeepsAttributesTyped(): void
    {
        $compiled = $this->compileView('components.signed-props');

        // The signature types the props, and $attributes is still declared as
        // ComponentAttributeBag even though the signature omits it, so the body
        // does not fall back to reading an untyped $attributes.
        $this->assertStringContainsString('/** @var string $title */', $compiled);
        $this->assertStringContainsString('/** @var string $price */', $compiled);
        $this->assertStringContainsString('/** @var \Illuminate\View\ComponentAttributeBag $attributes */', $compiled);
    }

    public function testClassComponentBodyGetsReflectedMembers(): void
    {
        $compiled = $this->compileView('components.panel');

        // A public property and a public zero-argument method (as a closure)
        // from the backing App\View\Components\Panel are declared.
        $this->assertStringContainsString('/** @var string $heading */', $compiled);
        $this->assertStringContainsString('/** @var \Closure(): string $badge */', $compiled);
        // Plus the component scope, since the template declares no @props.
        $this->assertStringContainsString('/** @var \Illuminate\View\ComponentSlot $slot */', $compiled);
        $this->assertStringContainsString('/** @var \Illuminate\View\ComponentAttributeBag $attributes */', $compiled);
    }

    public function testLivewireComponentBodyGetsInstanceScope(): void
    {
        $compiled = $this->compileView('livewire.wired-component');

        // The component instance is typed under each name Livewire exposes, and
        // its public property is a plain variable.
        $this->assertStringContainsString('/** @var \App\Livewire\WiredComponent $this */', $compiled);
        $this->assertStringContainsString('/** @var \App\Livewire\WiredComponent $__livewire */', $compiled);
        $this->assertStringContainsString('/** @var string $c */', $compiled);
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
