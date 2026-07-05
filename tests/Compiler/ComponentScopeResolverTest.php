<?php

declare(strict_types=1);

namespace Bladestan\Tests\Compiler;

use Bladestan\Compiler\ComponentScopeResolver;
use Illuminate\View\ComponentAttributeBag;
use Illuminate\View\ComponentSlot;
use PHPStan\Testing\PHPStanTestCase;
use PHPStan\Type\Type;
use PHPStan\Type\VerbosityLevel;

final class ComponentScopeResolverTest extends PHPStanTestCase
{
    private ComponentScopeResolver $componentScopeResolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->componentScopeResolver = self::getContainer()->getByType(ComponentScopeResolver::class);
    }

    public function testPropsGiveTypedScopeAndSlotButNotAttributes(): void
    {
        $scope = $this->componentScopeResolver->resolve(
            'components.alert',
            "@props(['type' => 'info', 'title', 'count' => 0, 'items' => [], 'open' => false])\n<div>{{ \$slot }}</div>",
        );

        $this->assertSame(ComponentSlot::class, $this->describe($scope, 'slot'));
        $this->assertSame('string', $this->describe($scope, 'componentName'));
        $this->assertSame('string', $this->describe($scope, 'type'));
        $this->assertSame('mixed', $this->describe($scope, 'title'));
        $this->assertSame('int', $this->describe($scope, 'count'));
        $this->assertSame('array', $this->describe($scope, 'items'));
        $this->assertSame('bool', $this->describe($scope, 'open'));

        // The compiled @props block defines $attributes itself, so it is not
        // re-declared here.
        $this->assertArrayNotHasKey('attributes', $scope);
    }

    public function testComponentViewWithoutPropsGetsAttributesSlotAndName(): void
    {
        $scope = $this->componentScopeResolver->resolve('components.card', '<div>{{ $slot }}</div>');

        $this->assertSame(ComponentAttributeBag::class, $this->describe($scope, 'attributes'));
        $this->assertSame(ComponentSlot::class, $this->describe($scope, 'slot'));
        $this->assertSame('string', $this->describe($scope, 'componentName'));
        $this->assertArrayNotHasKey('type', $scope);
    }

    public function testNonComponentViewGetsNoScope(): void
    {
        $scope = $this->componentScopeResolver->resolve('welcome', '<h1>{{ $title }}</h1>');

        $this->assertSame([], $scope);
    }

    public function testPropsAloneMarkANonConventionViewAsAComponent(): void
    {
        // A template outside the components. convention is still a component
        // when it declares @props.
        $scope = $this->componentScopeResolver->resolve('layout.card', "@props(['heading'])\n{{ \$slot }}");

        $this->assertSame('mixed', $this->describe($scope, 'heading'));
        $this->assertSame(ComponentSlot::class, $this->describe($scope, 'slot'));
    }

    /**
     * @return list<string>
     */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../../config/extension.neon'];
    }

    /**
     * @param array<string, Type> $scope
     */
    private function describe(array $scope, string $name): string
    {
        $this->assertArrayHasKey($name, $scope);

        return $scope[$name]->describe(VerbosityLevel::typeOnly());
    }
}
