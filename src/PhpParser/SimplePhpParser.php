<?php

declare(strict_types=1);

namespace TomasVotruba\Bladestan\PhpParser;

use PhpParser\Lexer;
use PhpParser\Node\Stmt;
use PhpParser\Parser;
use PhpParser\Parser\Php7;

final class SimplePhpParser
{
    private readonly Parser $nativePhpParser;

    public function __construct()
    {
        $this->nativePhpParser = new Php7(new Lexer());
    }

    /**
     * @return Stmt[]
     */
    public function parse(string $fileContents): array
    {
        $stmts = $this->nativePhpParser->parse($fileContents);
        if ($stmts === null) {
            return [];
        }

        return $stmts;
    }
}
