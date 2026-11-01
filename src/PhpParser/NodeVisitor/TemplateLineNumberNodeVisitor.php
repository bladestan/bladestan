<?php

declare(strict_types=1);

namespace Bladestan\PhpParser\NodeVisitor;

use Bladestan\Blade\TemplateLineMap;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Rewrites every node's line numbers from compiled PHP lines to template lines.
 *
 * PHPStan takes an error's line straight off the node it was reported on, so
 * putting template lines on the AST is what makes an error land on the
 * `.blade.php` line the user wrote, with no error formatter in between. It also
 * puts PHPStan's inline ignore comments, `--generate-baseline`, and
 * `ignoreErrors` entries scoped by line into template coordinates for free,
 * none of which a formatter can reach.
 *
 * File positions are deliberately left pointing into the compiled source: they
 * only feed the `--fix` applier, and a template is not something PHPStan can
 * rewrite in place anyway.
 */
final class TemplateLineNumberNodeVisitor extends NodeVisitorAbstract
{
    public function __construct(
        private readonly TemplateLineMap $templateLineMap,
    ) {
    }

    public function enterNode(Node $node): ?Node
    {
        $startLine = $node->getStartLine();
        if ($startLine > 0) {
            $node->setAttribute('startLine', $this->templateLineMap->templateLine($startLine));
        }

        $endLine = $node->getEndLine();
        if ($endLine > 0) {
            $node->setAttribute('endLine', $this->templateLineMap->templateLine($endLine));
        }

        return null;
    }
}
