<?php

declare(strict_types=1);

namespace Bladestan\Console;

use Bladestan\Console\ValueObject\RenderSite;
use PhpParser\ErrorHandler\Collecting;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;

/**
 * Finds every render site in a PHP file (see {@see RenderSiteVisitor}).
 *
 * Parsing the file, rather than scanning it with a regex, is what lets the
 * generator handle `compact(...)`, array literals, and multi-line data
 * arguments uniformly: the data argument is located by its AST node offsets.
 *
 * @see \Bladestan\Tests\Console\RenderSiteFinderTest
 */
final class RenderSiteFinder
{
    /**
     * @return list<RenderSite>
     */
    public function find(string $filePath): array
    {
        $code = @file_get_contents($filePath);
        if ($code === false) {
            return [];
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        // A collecting error handler keeps parse() from throwing on a file with
        // syntax errors: it returns whatever it could parse (or null), so one
        // unparseable file never aborts the scan.
        $statements = $parser->parse($code, new Collecting());
        if ($statements === null) {
            return [];
        }

        $renderSiteVisitor = new RenderSiteVisitor($filePath);
        $nodeTraverser = new NodeTraverser();
        $nodeTraverser->addVisitor($renderSiteVisitor);
        $nodeTraverser->traverse($statements);

        return $renderSiteVisitor->getRenderSites();
    }
}
