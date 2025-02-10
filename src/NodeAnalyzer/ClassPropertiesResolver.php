<?php

declare(strict_types=1);

namespace Bladestan\NodeAnalyzer;

use Illuminate\View\Component;
use Illuminate\View\ComponentSlot;
use Livewire\Component as LivewireComponent;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use ReflectionProperty;

final class ClassPropertiesResolver
{
    /**
     * @return array<string, Type>
     */
    public function resolve(ClassReflection $classReflection, Scope $scope): array
    {
        $reflectionClass = $classReflection->getNativeReflection();
        $result = [];

        foreach ($reflectionClass->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $propertyName = $property->getName();
            $result[$propertyName] = $classReflection->getProperty($propertyName, $scope)->getReadableType();
        }

        if ($reflectionClass->isSubclassOf(Component::class)) {
            $result += [
                'slot' => new ObjectType(ComponentSlot::class),
            ];
        } elseif ($reflectionClass->isSubclassOf(LivewireComponent::class)) {
            $objectType = new ObjectType($reflectionClass->getName());
            $result += [
                '__livewire' => $objectType,
                '_instance' => $objectType,
                'this' => $objectType,
            ];
        }

        return $result;
    }
}
