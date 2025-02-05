<?php

declare(strict_types=1);

namespace Bladestan\PhpParser\NodeVisitor;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\Stmt\InlineHTML;
use PhpParser\Node\Stmt\Nop;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

final class DeleteInlineHTML extends NodeVisitorAbstract
{
    /**
     * @see https://regex101.com/r/GqrJOW/1
     * @var string
     */
    private const TEMPLATE_FILE_NAME_AND_LINE_NUMBER_REGEX = '#/\*\* file: (.*?), line: (\d+) \*/#';

    public function leaveNode(Node $node): null|int|Nop
    {
        if ($node instanceof InlineHTML) {
            if (! preg_match_all(
                self::TEMPLATE_FILE_NAME_AND_LINE_NUMBER_REGEX,
                $node->value,
                $matches,
                PREG_SET_ORDER
            ) || ! $matches) {
                return NodeTraverser::REMOVE_NODE;
            }

            $docNop = new Nop();
            $docNop->setDocComment(new Doc(end($matches)[0]));
            return $docNop;
        }

        return null;
    }
}
