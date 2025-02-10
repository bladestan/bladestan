<?php

declare(strict_types=1);

namespace Bladestan\TemplateCompiler\NodeFactory;

use PhpParser\Comment\Doc;
use PhpParser\Node\Stmt\Nop;
use PHPStan\Type\ThisType;
use PHPStan\Type\Type;
use PHPStan\Type\VerbosityLevel;

final class VarDocNodeFactory
{
    /**
     * @param array<string, Type> $variablesAndTypes
     * @return list<Nop>
     */
    public function createDocNodes(array $variablesAndTypes): array
    {
        $values = [];
        foreach ($variablesAndTypes as $name => $type) {
            $typeString = $this->getTypeAsString($type);
            $docNop = new Nop();
            $docNop->setDocComment(new Doc("/** @var {$typeString} \${$name} */"));
            $values[] = $docNop;
        }

        return $values;
    }

    private function getTypeAsString(Type $type): string
    {
        if ($type instanceof ThisType) {
            return $type->getStaticObjectType()
                ->describe(VerbosityLevel::typeOnly());
        }

        return $type->describe(VerbosityLevel::typeOnly());
    }
}
