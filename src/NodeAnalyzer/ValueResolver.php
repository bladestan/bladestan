<?php

declare(strict_types=1);

namespace Bladestan\NodeAnalyzer;

use Bladestan\ValueObject\Types;
use Illuminate\Contracts\Support\Renderable;
use PHPStan\Type\ArrayType;
use PHPStan\Type\Constant\ConstantArrayTypeBuilder;
use PHPStan\Type\Constant\ConstantBooleanType;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\FloatType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\MixedType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;

class ValueResolver
{
    public function resolve(mixed $value): Type
    {
        if (is_bool($value)) {
            return new ConstantBooleanType($value);
        }

        if (is_int($value)) {
            return new ConstantIntegerType($value);
        }

        if (is_float($value)) {
            return new FloatType();
        }

        if (is_string($value)) {
            return new ConstantStringType($value);
        }

        if ($value instanceof Renderable) {
            return new StringType();
        }

        if (is_object($value)) {
            return new ObjectType(get_class($value));
        }

        if (is_array($value)) {
            if ($value === []) {
                return new ArrayType(new MixedType(), new MixedType());
            }

            $builder = ConstantArrayTypeBuilder::createEmpty();
            foreach ($value as $key => $val) {
                $keyType = is_string($key) ? new ConstantStringType($key) : new IntegerType();
                $builder->setOffsetValueType($keyType, $this->resolve($val));
            }

            return $builder->getArray();
        }

        return new MixedType();
    }

    public function toNative(mixed $value): string
    {
        $typeClass = Types::class;
        if (is_bool($value)) {
            return "{$typeClass}::getBool()";
        }

        if (is_int($value)) {
            return "{$typeClass}::getInt()";
        }

        if (is_float($value)) {
            return "{$typeClass}::getFloat()";
        }

        if (is_string($value) || $value instanceof Renderable) {
            return "{$typeClass}::getString()";
        }

        if (is_object($value)) {
            $className = get_class($value);
            return "resolve({$className}::class)";
        }

        if (is_array($value)) {
            return "{$typeClass}::getArray()";
        }

        return "{$typeClass}::getMixed()";
    }
}
