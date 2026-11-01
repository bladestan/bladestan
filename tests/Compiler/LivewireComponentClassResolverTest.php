<?php

declare(strict_types=1);

namespace Bladestan\Tests\Compiler;

use App\Contexts\Widgets\AliasedWidget;
use App\Livewire\WiredComponent;
use Bladestan\Compiler\LivewireComponentClassResolver;
use PHPStan\Testing\PHPStanTestCase;

final class LivewireComponentClassResolverTest extends PHPStanTestCase
{
    private LivewireComponentClassResolver $livewireComponentClassResolver;

    protected function setUp(): void
    {
        parent::setUp();

        // Building the analysis container is what boots the Laravel application
        // the resolver asks; without it there is no Livewire registration to find.
        self::getContainer();

        $this->livewireComponentClassResolver = new LivewireComponentClassResolver();
    }

    public function testAliasedComponentResolvesToItsRegisteredClass(): void
    {
        // cart.preview is registered as App\Contexts\Widgets\AliasedWidget (see
        // TestServiceProvider), which lives outside livewire.class_namespace:
        // building the name from that namespace would give App\Livewire\Cart\Preview.
        $this->assertSame(
            AliasedWidget::class,
            $this->livewireComponentClassResolver->resolve('cart.preview'),
        );
    }

    public function testDiscoveredComponentResolvesWithoutALeadingBackslash(): void
    {
        // Livewire returns a discovered class as it registered it, which can
        // carry a leading backslash. Compiled PHP and reflection both want the
        // bare name.
        $this->assertSame(
            WiredComponent::class,
            $this->livewireComponentClassResolver->resolve('wired-component'),
        );
    }

    public function testUnknownComponentResolvesToNull(): void
    {
        // Livewire names no class for it, so the caller keeps its own fallback
        // and an unresolvable tag still reports against the guessed name.
        $this->assertNull($this->livewireComponentClassResolver->resolve('does.not.exist'));
    }

    /**
     * @return list<string>
     */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../../config/extension.neon'];
    }
}
