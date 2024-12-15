<?php

declare(strict_types=1);

namespace Bladestan\TemplateCompiler\NodeFactory;

use Bladestan\TemplateCompiler\ValueObject\VariableAndType;
use PhpParser\Comment\Doc;
use PhpParser\Node\Stmt\Nop;

final class VarDocNodeFactory
{
    /**
     * @var array <string, Nop>
     */
    private static array $docNodes = [];

    public function __construct()
    {
        self::$docNodes = [];
    }

    /**
     * Preset doc block statically.
     *
     * Enables setting doc block at runtime, which was needed when the array was not in PHPParser function call.
     */
    public static function setDocBlock(string $variable, string $type): void
    {
        $prependVarTypesDocBlocks = sprintf('/** @var %s $%s */', $type, $variable);

        $docNop = new Nop();
        $docNop->setDocComment(new Doc($prependVarTypesDocBlocks));

        self::$docNodes[$variable] = $docNop;
    }

    /**
     * @param VariableAndType[] $variablesAndTypes
     * @return Nop[]
     */
    public function createDocNodes(array $variablesAndTypes): array
    {
        foreach ($variablesAndTypes as $variableAndType) {
            if (isset(self::$docNodes[$variableAndType->getVariable()])) {
                // avoids overwriting the same variable, if it is preset
                continue;
            }

            self::$docNodes[$variableAndType->getVariable()] = $this->createDocNop($variableAndType);
        }

        $values = array_values(self::$docNodes);

        // reset for next run
        self::$docNodes = [];

        return $values;
    }

    private function createDocNop(VariableAndType $variableAndType): Nop
    {
        $prependVarTypesDocBlocks = sprintf(
            '/** @var %s $%s */',
            $variableAndType->getTypeAsString(),
            $variableAndType->getVariable()
        );

        // doc types node
        $docNop = new Nop();
        $docNop->setDocComment(new Doc($prependVarTypesDocBlocks));

        return $docNop;
    }
}
