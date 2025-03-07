<?php

declare(strict_types=1);

namespace Bladestan\PhpParser;

use PhpParser\ConstExprEvaluationException;
use PhpParser\ConstExprEvaluator;
use PhpParser\Lexer;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Parser;
use PhpParser\Parser\Php7;
use PhpParser\PrettyPrinter\Standard;

/**
 * This class converts the string `"['foo', 'bar' => 'baz']"` to actual PHP array `[0 => "'foo'", 'bar' => "'baz'"]`
 *
 * @see \Bladestan\Tests\PHPParser\ArrayStringToArrayConverterTest
 */
final class ArrayStringToArrayConverter
{
    private readonly Parser $parser;

    public function __construct(
        private readonly Standard $standard,
        private readonly ConstExprEvaluator $constExprEvaluator
    ) {
        $this->parser = new Php7(new Lexer());
    }

    /**
     * @return array<string>
     */
    public function convert(string $array): array
    {
        $array = '<?php ' . $array . ';';

        $stmts = $this->parser->parse($array);
        if ($stmts === null) {
            return [];
        }

        if (count($stmts) !== 1) {
            return [];
        }

        if (! $stmts[0] instanceof Expression) {
            return [];
        }

        if (! $stmts[0]->expr instanceof Array_) {
            return [];
        }

        $array = $stmts[0]->expr;

        $result = [];

        foreach ($array->items as $item) {
            $value = $this->standard->prettyPrintExpr($item->value);

            if ($item->key instanceof Expr) {
                $key = $this->resolveKey($item->key);
                if (! is_string($key) && ! is_int($key)) {
                    continue;
                }

                $result[$key] = $value;
            } else {
                $result[] = $value;
            }
        }

        return $result;
    }

    private function resolveKey(Expr $expr): mixed
    {
        try {
            return $this->constExprEvaluator->evaluateDirectly($expr);
        } catch (ConstExprEvaluationException) {
            return null;
        }
    }
}
