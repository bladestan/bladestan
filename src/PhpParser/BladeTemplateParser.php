<?php

declare(strict_types=1);

namespace Bladestan\PhpParser;

use Bladestan\Blade\TemplateLineMap;
use Bladestan\Compiler\BladeToPHPCompiler;
use Bladestan\Discovery\TemplateDiscovery;
use Bladestan\Laravel\ApplicationBooter;
use Bladestan\PhpParser\NodeVisitor\TemplateLineNumberNodeVisitor;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Nop;
use PhpParser\NodeTraverser;
use PHPStan\DependencyInjection\Container;
use PHPStan\Parser\Parser;
use Throwable;

/**
 * Hands PHPStan the compiled form of a `.blade.php` file, in template
 * coordinates.
 *
 * PHPStan discovers `.blade.php` files on its own (its `fileExtensions` are
 * matched with a suffix, so `*.php` already covers them) and asks this parser
 * for their statements. Compiling here rather than out of band is what lets a
 * template be an ordinary analysed file: PHPStan's file discovery, result
 * cache, dependency graph, and parallel workers all treat it exactly like a
 * PHP file, with no compiled directory to add to `paths`, keep in sync, or
 * exclude from other rules. Node lines are rewritten to template lines before
 * the AST is returned, so errors, baselines, and inline ignores are already in
 * the user's coordinates when PHPStan sees them.
 *
 * Registered in place of PHPStan's own `pathRoutingParser`, wrapping it: every
 * file that is not a template is routed by the original, unchanged.
 *
 * @see \Bladestan\Tests\PhpParser\BladeTemplateParserTest
 */
final class BladeTemplateParser implements Parser
{
    /**
     * Compilation failures for the file being returned, read back by
     * {@see \Bladestan\Rules\TemplateCompilationErrorRule}. Carried on the AST
     * rather than in a side channel so it travels with the parse result through
     * PHPStan's parser cache and is discarded with it.
     */
    public const COMPILATION_ERRORS_ATTRIBUTE = 'bladestanCompilationErrors';

    private const TEMPLATE_SUFFIX = '.blade.php';

    private ?BladeToPHPCompiler $bladeToPHPCompiler = null;

    /**
     * Absolute template path => Laravel view name.
     *
     * @var array<string, string>|null
     */
    private ?array $viewNames = null;

    public function __construct(
        private readonly Parser $wrappedParser,
        private readonly Parser $compiledTemplateParser,
        private readonly Container $container,
    ) {
    }

    /**
     * PHPStan pushes the analysed file list onto whatever service is registered
     * as `pathRoutingParser`, without an interface declaring it: the routing it
     * feeds (rich parser for analysed files, simple parser for the rest) is the
     * wrapped parser's business, so it is forwarded untouched.
     *
     * @param list<string> $files
     */
    public function setAnalysedFiles(array $files): void
    {
        if (! method_exists($this->wrappedParser, 'setAnalysedFiles')) {
            return;
        }

        $this->wrappedParser->setAnalysedFiles($files);
    }

    /**
     * @return list<Stmt>
     */
    public function parseFile(string $file): array
    {
        if (! str_ends_with($file, self::TEMPLATE_SUFFIX)) {
            return array_values($this->wrappedParser->parseFile($file));
        }

        return $this->parseTemplate($file);
    }

    /**
     * @return list<Stmt>
     */
    public function parseString(string $sourceCode): array
    {
        return array_values($this->wrappedParser->parseString($sourceCode));
    }

    /**
     * @return list<Stmt>
     */
    private function parseTemplate(string $file): array
    {
        // Third-party templates cannot be annotated with a signature and their
        // errors are not actionable, so they are left with no statements at
        // all. A template declares no PHP symbol, so nothing that reflects over
        // one loses anything by this; it only keeps a `vendor` directory in
        // `scanDirectories` from compiling every framework view.
        if (str_contains(str_replace('\\', '/', $file), '/vendor/')) {
            return [];
        }

        try {
            return $this->compileAndParse($file);
        } catch (Throwable $throwable) {
            return $this->diagnosticOnly(sprintf(
                'View [%s] could not be compiled: %s',
                basename($file),
                $throwable->getMessage(),
            ), 'bladestan.compilation');
        }
    }

    /**
     * @return list<Stmt>
     * @throws Throwable
     */
    private function compileAndParse(string $file): array
    {
        $viewName = $this->viewName($file);
        if ($viewName === null) {
            return $this->diagnosticOnly(
                sprintf(
                    "Template %s is not reachable through any of Laravel's registered view paths, so it cannot be "
                    . 'compiled. Move it under a registered view directory, or register its directory with the view '
                    . 'finder.',
                    basename($file),
                ),
                'bladestan.unreachableTemplate',
            );
        }

        $phpFileContentsWithLineMap = $this->compiler()
            ->compileStandalone($file, $viewName);

        $statements = array_values($this->compiledTemplateParser->parseString($phpFileContentsWithLineMap->phpFileContents));

        $templateLineMap = TemplateLineMap::fromResolvedLines($phpFileContentsWithLineMap->phpToTemplateLines);
        $this->rewriteLineNumbers($statements, $templateLineMap);

        $errors = [];
        foreach ($phpFileContentsWithLineMap->errors as [$message, $identifier]) {
            $errors[] = [
                'message' => $message,
                'identifier' => $identifier,
            ];
        }

        if ($statements === []) {
            $statements = [new Nop()];
        }

        $statements[0]->setAttribute(self::COMPILATION_ERRORS_ATTRIBUTE, $errors);

        return $statements;
    }

    /**
     * @param list<Stmt> $statements
     */
    private function rewriteLineNumbers(array $statements, TemplateLineMap $templateLineMap): void
    {
        $nodeTraverser = new NodeTraverser();
        $nodeTraverser->addVisitor(new TemplateLineNumberNodeVisitor($templateLineMap));
        $nodeTraverser->traverse($statements);

        if ($statements === []) {
            return;
        }

        // The rich parser records which lines its inline ignore comments apply
        // to, in compiled coordinates, as an attribute on the first statement.
        // Those cannot be translated: a same-line ignore belongs to the anchor
        // at or above it while a next-line ignore belongs to the anchor below,
        // and by the time the lines are recorded the two are indistinguishable.
        // Guessing silences a line the user did not write the comment for, so
        // the record is dropped instead and an inline ignore inside a template
        // does nothing. `ignoreErrors` in the config still works, and matches
        // on the template's own path.
        $statements[0]->setAttribute('linesToIgnore', []);
        $statements[0]->setAttribute('linesToIgnoreParseErrors', []);
    }

    /**
     * A template that could not be compiled still has to leave a signal, or it
     * drops out of analysis without a trace. One empty statement carrying the
     * failure gives TemplateCompilationErrorRule something to report.
     *
     * @return list<Stmt>
     */
    private function diagnosticOnly(string $message, string $identifier): array
    {
        $nop = new Nop();
        $nop->setAttribute(self::COMPILATION_ERRORS_ATTRIBUTE, [[
            'message' => $message,
            'identifier' => $identifier,
        ]]);

        return [$nop];
    }

    /**
     * @throws Throwable
     */
    private function viewName(string $file): ?string
    {
        if ($this->viewNames === null) {
            ApplicationBooter::boot();
            $this->viewNames = $this->container->getByType(TemplateDiscovery::class)
                ->discoverTemplates();
        }

        return $this->viewNames[$file] ?? $this->viewNames[realpath($file) ?: $file] ?? null;
    }

    /**
     * The compiler reflects Laravel's view factory and Blade compiler, so it
     * cannot be built before the application is booted — and this parser is
     * constructed while PHPStan builds its container, long before that. Pulling
     * it on the first template keeps the two orderings independent.
     *
     * @throws Throwable
     */
    private function compiler(): BladeToPHPCompiler
    {
        if (! $this->bladeToPHPCompiler instanceof BladeToPHPCompiler) {
            ApplicationBooter::boot();
            $this->bladeToPHPCompiler = $this->container->getByType(BladeToPHPCompiler::class);
        }

        return $this->bladeToPHPCompiler;
    }
}
