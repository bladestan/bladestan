<?php

declare(strict_types=1);

namespace Bladestan\Tests\Compiler;

use App\Livewire\WiredComponent;
use App\View\Components\BackedComponent;
use App\View\Components\Panel;
use Bladestan\Compiler\ComponentClassShapeHasher;
use PHPUnit\Framework\TestCase;

final class ComponentClassShapeHasherTest extends TestCase
{
    private ComponentClassShapeHasher $componentClassShapeHasher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->componentClassShapeHasher = new ComponentClassShapeHasher();
    }

    public function testHashIsStableForTheSameClass(): void
    {
        $this->assertSame(
            (new ComponentClassShapeHasher())->hash(Panel::class),
            (new ComponentClassShapeHasher())->hash(Panel::class),
        );
    }

    public function testDifferentConstructorsHashDifferently(): void
    {
        // Panel takes a single required string; BackedComponent takes a
        // required string plus an optional one, which is exactly the difference
        // the compiled call site is built from.
        $this->assertNotSame(
            $this->componentClassShapeHasher->hash(Panel::class),
            $this->componentClassShapeHasher->hash(BackedComponent::class),
        );
    }

    public function testLivewireMountSignatureIsCovered(): void
    {
        // App\Livewire\WiredComponent has no constructor: its mount()
        // parameters are what LivewireTagCompiler reflects, so the hash has to
        // read them rather than only the constructor.
        $this->assertNotSame(
            $this->componentClassShapeHasher->hash(WiredComponent::class),
            $this->componentClassShapeHasher->hash(Panel::class),
        );
    }

    public function testAbsentClassHashesToItsOwnValue(): void
    {
        $absent = $this->componentClassShapeHasher->hash('App\View\Components\NotCreatedYet');

        // A class that does not exist yet must not share a hash with any real
        // shape, or creating it would leave every template that renders the
        // component compiled as an anonymous one.
        $this->assertNotSame($absent, $this->componentClassShapeHasher->hash(Panel::class));
        $this->assertSame($absent, $this->componentClassShapeHasher->hash('App\View\Components\NotCreatedYet'));
    }

    public function testHashesEveryRequestedClass(): void
    {
        $hashes = $this->componentClassShapeHasher->hashAll([Panel::class, BackedComponent::class]);

        $this->assertSame([Panel::class, BackedComponent::class], array_keys($hashes));
        $this->assertSame($this->componentClassShapeHasher->hash(Panel::class), $hashes[Panel::class]);
    }
}
