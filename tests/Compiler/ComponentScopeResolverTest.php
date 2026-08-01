<?php

declare(strict_types=1);

namespace Bladestan\Tests\Compiler;

use App\Contexts\Widgets\AliasedWidget;
use App\Livewire\WiredComponent;
use Bladestan\Compiler\ComponentScopeResolver;
use Illuminate\View\ComponentAttributeBag;
use Illuminate\View\ComponentSlot;
use PHPStan\Testing\PHPStanTestCase;

final class ComponentScopeResolverTest extends PHPStanTestCase
{
    private ComponentScopeResolver $componentScopeResolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->componentScopeResolver = self::getContainer()->getByType(ComponentScopeResolver::class);
    }

    public function testPropsGiveTypedScopeSlotAndAttributes(): void
    {
        $scope = $this->componentScopeResolver->resolve(
            'components.alert',
            "@props(['type' => 'info', 'title', 'count' => 0, 'items' => [], 'open' => false])\n<div>{{ \$slot }}</div>",
        );

        $this->assertSame('\\' . ComponentSlot::class, $scope['slot'] ?? null);
        $this->assertSame('string', $scope['componentName'] ?? null);
        $this->assertSame('string', $scope['type'] ?? null);
        $this->assertSame('mixed', $scope['title'] ?? null);
        $this->assertSame('int', $scope['count'] ?? null);
        $this->assertSame('array', $scope['items'] ?? null);
        $this->assertSame('bool', $scope['open'] ?? null);

        // $attributes is declared authoritatively even with @props present, so
        // the body keeps it typed regardless of the compiled @props shape.
        $this->assertSame('\\' . ComponentAttributeBag::class, $scope['attributes'] ?? null);
    }

    public function testPropsSignatureReturnsOnlyPropsTypedFromDefaults(): void
    {
        $signature = $this->componentScopeResolver->propsSignature(
            "@props(['url', 'selected', 'label', 'indent' => false])\n<a href=\"{{ \$url }}\">{{ \$label }}</a>",
        );

        // Only the declared props, no Blade-injected internals ($slot,
        // $attributes, $componentName): a caller passes props, not those.
        $this->assertSame(
            [
                'url' => 'mixed',
                'selected' => 'mixed',
                'label' => 'mixed',
                'indent' => 'bool',
            ],
            $signature,
        );
    }

    public function testPropsSignatureSpansACallDefaultAndNestedArray(): void
    {
        // The foo(1) default contains a ")" and 'nested' is an array literal;
        // every prop after them must still be read, not lost to a scan that
        // stops at the first ")" or "]".
        $signature = $this->componentScopeResolver->propsSignature(
            "@props(['a' => foo(1), 'nested' => ['x' => 1], 'label' => 'hi'])\n<div>{{ \$slot }}</div>",
        );

        $this->assertSame(
            [
                'a' => 'mixed',
                'nested' => 'array',
                'label' => 'string',
            ],
            $signature,
        );
    }

    public function testPropsSignatureIsNullWithoutPropsDirective(): void
    {
        $this->assertNull($this->componentScopeResolver->propsSignature('<div>{{ $slot }}</div>'));
    }

    public function testPropsInsideACommentAreIgnored(): void
    {
        // Blade discards the comment, so the commented @props declares nothing.
        $this->assertNull(
            $this->componentScopeResolver->propsSignature("{{-- @props(['type' => 'info']) --}}\n<div>{{ \$slot }}</div>"),
        );
    }

    public function testPropsInsideVerbatimAreIgnored(): void
    {
        $this->assertNull(
            $this->componentScopeResolver->propsSignature("@verbatim\n@props(['type' => 'info'])\n@endverbatim"),
        );
    }

    public function testRealPropsWinOverACommentedDeclaration(): void
    {
        $signature = $this->componentScopeResolver->propsSignature(
            "{{-- @props(['stale' => 1]) --}}\n@props(['type' => 'info'])",
        );

        $this->assertSame([
            'type' => 'string',
        ], $signature);
    }

    public function testComponentViewWithoutPropsGetsAttributesSlotAndName(): void
    {
        $scope = $this->componentScopeResolver->resolve('components.card', '<div>{{ $slot }}</div>');

        $this->assertSame('\\' . ComponentAttributeBag::class, $scope['attributes'] ?? null);
        $this->assertSame('\\' . ComponentSlot::class, $scope['slot'] ?? null);
        $this->assertSame('string', $scope['componentName'] ?? null);
        $this->assertArrayNotHasKey('type', $scope);
    }

    public function testNonComponentViewGetsNoScope(): void
    {
        $scope = $this->componentScopeResolver->resolve('welcome', '<h1>{{ $title }}</h1>');

        $this->assertSame([], $scope);
    }

    public function testLivewireComponentViewGetsInstanceAndPublicProperties(): void
    {
        // livewire.wired-component resolves to App\Livewire\WiredComponent via the
        // Livewire class-namespace convention.
        $scope = $this->componentScopeResolver->resolve('livewire.wired-component', '<div>{{ $c }} {{ $this->c }}</div>');

        // The component instance under each name Livewire exposes it as.
        $this->assertSame('\\' . WiredComponent::class, $scope['this'] ?? null);
        $this->assertSame('\\' . WiredComponent::class, $scope['_instance'] ?? null);
        $this->assertSame('\\' . WiredComponent::class, $scope['__livewire'] ?? null);
        // Public property exposed as a plain variable.
        $this->assertSame('string', $scope['c'] ?? null);
        // Livewire actions (public methods) are not view variables.
        $this->assertArrayNotHasKey('mount', $scope);
        // Not a Blade component, so no slot/attributes.
        $this->assertArrayNotHasKey('slot', $scope);
        $this->assertArrayNotHasKey('attributes', $scope);
    }

    public function testPropsAloneMarkANonConventionViewAsAComponent(): void
    {
        // A template outside the components. convention is still a component
        // when it declares @props.
        $scope = $this->componentScopeResolver->resolve('layout.card', "@props(['heading'])\n{{ \$slot }}");

        $this->assertSame('mixed', $scope['heading'] ?? null);
        $this->assertSame('\\' . ComponentSlot::class, $scope['slot'] ?? null);
    }

    public function testBackingClassPublicMembersAreInjected(): void
    {
        // components.panel resolves to App\View\Components\Panel through the
        // registered component namespace (see TestServiceProvider).
        $scope = $this->componentScopeResolver->resolve('components.panel', '<div>{{ $heading }} {{ $badge() }} {{ $slot }}</div>');

        // Public readonly property.
        $this->assertSame('string', $scope['heading'] ?? null);
        // Public zero-argument method is exposed as a closure.
        $this->assertSame('\Closure(): string', $scope['badge'] ?? null);
        // A method requiring an argument is not a view variable.
        $this->assertArrayNotHasKey('format', $scope);
        // render() and other framework methods are never exposed.
        $this->assertArrayNotHasKey('render', $scope);
        // Component scope is still present alongside the reflected members.
        $this->assertSame('\\' . ComponentSlot::class, $scope['slot'] ?? null);
        $this->assertSame('\\' . ComponentAttributeBag::class, $scope['attributes'] ?? null);
    }

    public function testBackingClassResolvesForAViewUnderARegisteredViewNamespace(): void
    {
        // A template living under a registered view namespace is named with the
        // namespace prefix and the anonymous-component directory both. Dropping
        // only the prefix leaves the directory to double into the class name
        // (App\View\Components\Components\Panel), so nothing backs the component
        // and every member it reads becomes an undefined variable. Adding a view
        // namespace must not change which class backs a component.
        $scope = $this->componentScopeResolver->resolve('skeleton::components.panel', '<div>{{ $heading }}</div>');

        $this->assertSame('string', $scope['heading'] ?? null);
        $this->assertSame('\Closure(): string', $scope['badge'] ?? null);
    }

    public function testBackingClassResolvesForAnAnonymousDirectoryRootedAtTheViewNamespace(): void
    {
        // The anonymous-component directory registered as "rooted::components"
        // carries the namespace prefix itself; the view name it produces does
        // not repeat it, so the two have to be compared bare.
        $scope = $this->componentScopeResolver->resolve('rooted::components.panel', '<div>{{ $heading }}</div>');

        $this->assertSame('string', $scope['heading'] ?? null);
        $this->assertSame('\Closure(): string', $scope['badge'] ?? null);
    }

    public function testClassComponentAddressedThroughItsNamespaceAloneStillResolves(): void
    {
        // No anonymous-component directory in the name: the prefix is all that
        // comes off.
        $scope = $this->componentScopeResolver->resolve('skeleton::panel', '<div>{{ $heading }}</div>');

        $this->assertSame('string', $scope['heading'] ?? null);
    }

    public function testAliasedLivewireViewGetsTheRegisteredComponentScope(): void
    {
        // livewire.cart.preview is the view of the cart.preview component, which
        // is registered as App\Contexts\Widgets\AliasedWidget outside
        // livewire.class_namespace (see TestServiceProvider).
        $scope = $this->componentScopeResolver->resolve('livewire.cart.preview', '<div>{{ $label }}</div>');

        $this->assertSame('\\' . AliasedWidget::class, $scope['this'] ?? null);
        $this->assertSame('string', $scope['label'] ?? null);
    }

    /**
     * @return list<string>
     */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../../config/extension.neon'];
    }
}
