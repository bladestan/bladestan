<?php

declare(strict_types=1);

namespace Bladestan\Compiler;

use Bladestan\Blade\PhpLineToTemplateLineResolver;
use Bladestan\Exception\ShouldNotHappenException;
use Bladestan\NodeAnalyzer\ValueResolver;
use Bladestan\PhpParser\ArrayStringToArrayConverter;
use Bladestan\PhpParser\NodeVisitor\AddLoopVarTypeToForeachNodeVisitor;
use Bladestan\PhpParser\NodeVisitor\DeleteInlineHTML;
use Bladestan\PhpParser\NodeVisitor\IncludeCollector;
use Bladestan\PhpParser\NodeVisitor\RemoveLivewireCompilerArtifacts;
use Bladestan\PhpParser\NodeVisitor\TransformEach;
use Bladestan\PhpParser\NodeVisitor\TransformIncludes;
use Bladestan\PhpParser\NodeVisitor\TransformIncludesToViewCalls;
use Bladestan\PhpParser\SimplePhpParser;
use Bladestan\TemplateCompiler\NodeFactory\VarDocNodeFactory;
use Bladestan\ValueObject\AbstractInlinedElement;
use Bladestan\ValueObject\ComponentAndVariables;
use Bladestan\ValueObject\IncludedViewAndVariables;
use Bladestan\ValueObject\PhpFileContentsWithLineMap;
use Bladestan\ValueObject\TemplateSignature;
use Bladestan\ValueObject\ViewDataCollector;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ViewErrorBag;
use Illuminate\View\AnonymousComponent;
use Illuminate\View\Compilers\BladeCompiler;
use InvalidArgumentException;
use PhpParser\Comment\Doc;
use PhpParser\Error as ParserError;
use PhpParser\Node;
use PhpParser\Node\Stmt\Nop;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter\Standard;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\VerbosityLevel;
use ReflectionClass;
use ReflectionNamedType;
use Throwable;

final class BladeToPHPCompiler
{
    /**
     * Version of the compiled standalone output format. Bump to invalidate all
     * incrementally compiled templates when the generated PHP or the on-disk
     * output layout changes, so a stale tree from an older scheme is wiped
     * instead of leaving orphans the per-view prune cannot reach.
     */
    private const COMPILED_OUTPUT_VERSION = 4;

    /**
     * @see https://regex101.com/r/Fo7sHW/1
     * @var string
     */
    private const COMPONENT_REGEX = '/if \(isset\(\$component\)\).+?\$component = (.*?)::resolve\(.+?\$component->withAttributes\(\[.*?\]\);/s';

    /**
     * @see https://regex101.com/r/XGSsgA/1
     * @var string
     */
    private const ANONYMOUS_COMPONENT_REGEX = '/Illuminate\\\\View\\\\AnonymousComponent::resolve\(\[\'view\' => \'([^\']+)\', *\'data\' => (\[.*?\])\] \+ \(isset\(\$attributes\)/s';

    /**
     * @see https://regex101.com/r/B3BbxW/1
     * @var string
     */
    private const BACKED_COMPONENT_REGEX = '/if \(isset\(\$component\)\).+?\$component = (.*?)::resolve\((\[(?:.*?)?\]) \+ \(isset\(\$attributes\).+?\$component->withAttributes\(\[.*?\]\);/s';

    /**
     * @see https://regex101.com/r/mt3PUM/1
     * @var string
     */
    private const COMPONENT_END_REGEX = '/echo \$__env->renderComponent\(\);.+?unset\(\$__componentOriginal.+?}/s';

    /**
     * Matches every import form PHP allows after `use`: plain, `function`, `const`, and an alias.
     * The excluded characters keep the match away from prose: a quote, parenthesis or semicolon
     * ends it, so neither a closure's `use ($var)` clause nor the word "use" inside a string
     * literal is mistaken for an import.
     */
    private const IMPORT_REGEX = '/(?<=^|\s)use +(?:function +|const +)?[^ \')(;]+(?: +as +\w+)?;/';

    /**
     * @var list<array{0: string, 1: string}>
     */
    private array $errors;

    /**
     * @var array<string, Type>
     */
    private readonly array $shared;

    /**
     * @var array<string, string>
     */
    private readonly array $sharedNative;

    private readonly ViewFactory $viewFactory;

    public function __construct(
        private readonly Filesystem $fileSystem,
        private readonly BladeCompiler $bladeCompiler,
        private readonly Standard $printerStandard,
        private readonly ValueResolver $valueResolver,
        private readonly VarDocNodeFactory $varDocNodeFactory,
        private readonly PhpLineToTemplateLineResolver $phpLineToTemplateLineResolver,
        private readonly ArrayStringToArrayConverter $arrayStringToArrayConverter,
        private readonly FileNameAndLineNumberAddingPreCompiler $fileNameAndLineNumberAddingPreCompiler,
        private readonly LivewireTagCompiler $livewireTagCompiler,
        private readonly SimplePhpParser $simplePhpParser,
        private readonly SignatureExtractor $signatureExtractor,
        private readonly ComponentScopeResolver $componentScopeResolver,
    ) {
        $this->viewFactory = resolve(ViewFactory::class);
        $errorClass = ViewErrorBag::class;
        $shared = [
            'errors' => new ObjectType($errorClass),
        ];
        $sharedNative = [
            'errors' => "resolve({$errorClass}::class)",
        ];
        foreach ($this->viewFactory->getShared() as $name => $value) {
            $shared[(string) $name] = $this->valueResolver->resolve($value);
            $sharedNative[(string) $name] = $this->valueResolver->toNative($value);
        }

        $this->shared = $shared;
        $this->sharedNative = $sharedNative;
    }

    /**
     * Hash of compilation inputs that aren't derivable from a template's own
     * source: shared variables (View::share), framework version, and the
     * compiled-output format itself. The bootstrap manifest uses this to
     * detect when every template must be recompiled. Bump COMPILED_OUTPUT_VERSION
     * whenever the shape of the generated PHP changes.
     */
    public function getCompilationContextHash(): string
    {
        $sharedTypes = [];
        foreach ($this->shared as $name => $type) {
            $sharedTypes[$name] = $type->describe(VerbosityLevel::cache());
        }

        return hash('xxh128', serialize([
            self::COMPILED_OUTPUT_VERSION,
            defined('LARAVEL_VERSION') ? LARAVEL_VERSION : '',
            $this->sharedNative,
            $sharedTypes,
        ]));
    }

    /**
     * Compile a blade template standalone — types come from @bladestan-signature
     * instead of call-site arguments. Used by Phase 1 bootstrap compilation.
     *
     * @param string $resolvedTemplateFilePath Absolute path to the .blade.php file
     * @param string $viewName Laravel view name (e.g. 'welcome', 'layouts.app')
     */
    public function compileStandalone(
        string $resolvedTemplateFilePath,
        string $viewName,
    ): PhpFileContentsWithLineMap {
        $this->errors = [];

        $fileContents = @file_get_contents($resolvedTemplateFilePath);
        if ($fileContents === false) {
            return new PhpFileContentsWithLineMap(
                "<?php\n",
                [],
                [["Cannot read file: {$resolvedTemplateFilePath}", 'bladestan.io']],
            );
        }

        // Extract signature and strip the signature docblock (explicit or
        // implicit) so it doesn't survive into the compiled output and
        // duplicate the @var annotations we emit below.
        $templateSignature = $this->signatureExtractor->extract($fileContents);
        $fileContents = $this->signatureExtractor->stripSignatureBlock($fileContents);
        $fileContents = $this->signatureExtractor->stripImplicitSignatureBlock($fileContents);

        // @extends is not a call site — the parent's requirements are enforced
        // at the child's call sites via signature merging.
        $fileContents = $this->signatureExtractor->stripExtends($fileContents);

        // Variables Blade injects into a component body ($attributes, $slot,
        // $componentName, @props). Read before compilation, since compiling
        // consumes the @props directive. Empty for non-component templates.
        $componentScope = $this->componentScopeResolver->resolve($viewName, $fileContents);

        // Get view composer data
        $viewData = $this->getViewData($viewName);

        // Compile blade → PHP without inlining. @include directives become
        // view() calls that ViewCallSiteRule validates against the included
        // template's own signature; each template is compiled exactly once.
        $phpCode = "<?php\n\n" . $this->compileWithoutInlining($resolvedTemplateFilePath, $fileContents);
        $phpCode = $this->resolveComponents($phpCode);
        $phpCode = $this->bubbleUpImports($phpCode);

        // Decorate with @var annotations from signature + shared variables
        $phpCode = $this->decoratePhpContentStandalone($phpCode, $templateSignature, $viewData, $componentScope);

        // Add source tracking header after the <?php tag
        $sourceHeader = "// @bladestan-source: {$resolvedTemplateFilePath}";
        $phpCode = preg_replace('/^<\?php\n/', "<?php\n{$sourceHeader}\n", $phpCode) ?? $phpCode;

        $phpLinesToTemplateLines = $this->phpLineToTemplateLineResolver->resolve($phpCode);

        return new PhpFileContentsWithLineMap($phpCode, $phpLinesToTemplateLines, $this->errors);
    }

    /**
     * @param array<string, Type> $parametersArray
     */
    public function compileContent(
        string $resolvedTemplateFilePath,
        string $viewName,
        string $fileContents,
        array $parametersArray
    ): PhpFileContentsWithLineMap {
        $this->errors = [];

        $variablesAndTypes = $this->getViewData($viewName)
            + $parametersArray;

        $phpCode = "<?php\n\n" . $this->inlineInclude(
            $resolvedTemplateFilePath,
            $fileContents,
            array_keys($variablesAndTypes)
        );
        $phpCode = $this->resolveComponents($phpCode);
        $phpCode = $this->bubbleUpImports($phpCode);

        $phpCode = $this->decoratePhpContent($phpCode, $variablesAndTypes);

        $phpLinesToTemplateLines = $this->phpLineToTemplateLineResolver->resolve($phpCode);
        return new PhpFileContentsWithLineMap($phpCode, $phpLinesToTemplateLines, $this->errors);
    }

    /**
     * @return array<string, Type>
     */
    private function getViewData(string $viewName): array
    {
        $data = $this->getViewDataRaw($viewName);

        $viewData = [];
        foreach ($data as $name => $value) {
            $viewData[(string) $name] = $this->valueResolver->resolve($value);
        }

        return $viewData;
    }

    /**
     * @return array<string, string>
     */
    private function getViewDataNative(string $viewName): array
    {
        $data = $this->getViewDataRaw($viewName);

        $viewData = [];
        foreach ($data as $name => $value) {
            $viewData[(string) $name] = $this->valueResolver->toNative($value);
        }

        return $viewData;
    }

    /**
     * @return array<string, mixed>
     */
    private function getViewDataRaw(string $viewName): array
    {
        $viewDataCollector = new ViewDataCollector($viewName, $this->viewFactory);
        try {
            /** @throws Throwable */
            $this->viewFactory->callComposer($viewDataCollector);
        } catch (Throwable $throwable) {
            $this->errors[] = [$throwable->getMessage(), 'bladestan.data'];
            return [];
        }

        return $viewDataCollector->getData();
    }

    /**
     * Compile a single blade template to PHP without inlining includes.
     * Includes become view() call sites; no recursion into other templates.
     */
    private function compileWithoutInlining(string $filePath, string $fileContents): string
    {
        $fileContents = $this->fileNameAndLineNumberAddingPreCompiler
            ->completeLineCommentsToBladeContents($filePath, $fileContents);

        $rawPhpContent = '';
        try {
            /** @throws InvalidArgumentException */
            $compiledBlade = $this->bladeCompiler->compileString($fileContents);
            $stmts = $this->traverseStmtsWithVisitors($compiledBlade, [
                new RemoveLivewireCompilerArtifacts(),
                new DeleteInlineHTML(),
                new AddLoopVarTypeToForeachNodeVisitor(),
                new TransformEach(),
                new TransformIncludes(),
            ]);
            // Separate traversal: TransformEach/TransformIncludes produce the
            // `echo $__env->make(...)->render()` statements this visitor matches.
            $stmts = $this->traverseNodesWithVisitors($stmts, [new TransformIncludesToViewCalls()]);
            $rawPhpContent = $this->printerStandard->prettyPrint($stmts) . "\n";
        } catch (ParserError) {
            $relativeFilePath = $this->fileNameAndLineNumberAddingPreCompiler->getRelativePath($filePath);
            $this->errors[] = ["View [{$relativeFilePath}] contains syntx errors.", 'bladestan.parsing'];
        } catch (InvalidArgumentException $invalidArgumentException) {
            $this->errors[] = [$invalidArgumentException->getMessage(), 'bladestan.missing'];
        }

        return $this->livewireTagCompiler->replace($rawPhpContent);
    }

    /**
     * @param array<string> $allVariablesList
     */
    private function inlineInclude(string $filePath, string $fileContents, array $allVariablesList): string
    {
        // Precompile contents to add template file name and line numbers
        $fileContents = $this->fileNameAndLineNumberAddingPreCompiler
            ->completeLineCommentsToBladeContents($filePath, $fileContents);

        // Extract PHP content from HTML and PHP mixed content
        $rawPhpContent = '';
        try {
            /** @throws InvalidArgumentException */
            $compiledBlade = $this->bladeCompiler->compileString($fileContents);
            $stmts = $this->traverseStmtsWithVisitors($compiledBlade, [
                new RemoveLivewireCompilerArtifacts(),
                new DeleteInlineHTML(),
                new AddLoopVarTypeToForeachNodeVisitor(),
                new TransformEach(),
                new TransformIncludes(),
            ]);
            $rawPhpContent = $this->printerStandard->prettyPrint($stmts) . "\n";
        } catch (ParserError) {
            $filePath = $this->fileNameAndLineNumberAddingPreCompiler->getRelativePath($filePath);
            $this->errors[] = ["View [{$filePath}] contains syntx errors.", 'bladestan.parsing'];
        } catch (InvalidArgumentException $invalidArgumentException) {
            $this->errors[] = [$invalidArgumentException->getMessage(), 'bladestan.missing'];
        }

        $rawPhpContent = $this->livewireTagCompiler->replace($rawPhpContent);

        // Recursively fetch and compile includes
        foreach ($this->getIncludes($rawPhpContent) as $inlinedElement) {
            try {
                /** @throws InvalidArgumentException */
                $includedFilePath = $this->viewFactory->getFinder()
                    ->find($inlinedElement->includedViewName);
                $includedContent = $this->fileSystem->get($includedFilePath);
            } catch (InvalidArgumentException|FileNotFoundException $exception) {
                $includedFilePath = '';
                $includedContent = '';
                $this->errors[] = [$exception->getMessage(), 'bladestan.missing'];
            }

            $includedContent = $inlinedElement->preprocessTemplate($includedContent, array_keys($this->shared));
            $includedContent = $this->inlineInclude(
                $includedFilePath,
                $includedContent,
                $inlinedElement->getInnerScopeVariableNames($allVariablesList)
            );

            $rawPhpContent = str_replace(
                $inlinedElement->rawPhpContent,
                $inlinedElement->generateInlineRepresentation($includedContent),
                $rawPhpContent
            );
        }

        return $rawPhpContent;
    }

    private function bubbleUpImports(string $rawPhpContent): string
    {
        preg_match_all(self::IMPORT_REGEX, $rawPhpContent, $imports);
        foreach ($imports[0] as $import) {
            $rawPhpContent = str_replace($import, '', $rawPhpContent);
        }

        $import = implode("\n", array_unique($imports[0]));
        return str_replace("<?php\n", "<?php\n{$import}", $rawPhpContent);
    }

    private function resolveComponents(string $rawPhpContent): string
    {
        preg_match_all(self::BACKED_COMPONENT_REGEX, $rawPhpContent, $components, PREG_SET_ORDER);
        foreach ($components as $component) {
            $class = $component[1];
            $arrayString = trim($component[2], ' ,');
            $attributes = $this->convertComponentData($arrayString, $class);

            // Resolve any additional required arguments
            if (class_exists($class) && method_exists($class, '__construct')) {
                $parameters = (new ReflectionClass($class))->getMethod('__construct')
                    ->getParameters();
                foreach ($parameters as $parameter) {
                    if ($parameter->isDefaultValueAvailable()) {
                        continue;
                    }

                    $paramName = $parameter->getName();
                    if (isset($attributes[$paramName])) {
                        continue;
                    }

                    $paramType = $parameter->getType();
                    if (! $paramType instanceof ReflectionNamedType) {
                        continue;
                    }

                    if ($paramType->allowsNull()) {
                        $attributes[$paramName] = 'null';
                        continue;
                    }

                    $paramClass = $paramType->getName();
                    if (class_exists($paramClass) || interface_exists($paramClass)) {
                        $attributes[$paramName] = "resolve({$paramClass}::class)";
                        continue;
                    }
                }
            }

            $attrString = collect($attributes)
                ->map(fn (string $value, string $attribute): string => "{$attribute}: {$value}")
                ->implode(', ');
            $rawPhpContent = str_replace($component[0], "\$component = new {$class}({$attrString});", $rawPhpContent);
        }

        return preg_replace(
            self::COMPONENT_END_REGEX,
            '',
            $rawPhpContent
        ) ?? throw new ShouldNotHappenException('preg_replace error');
    }

    /**
     * A component's data array is located with a regular expression, so an unusual attribute value
     * can still end the capture in the wrong place. Report that against the template and carry on
     * with no data for this one component, rather than letting the parse error escape and abort the
     * whole run without naming a file.
     *
     * @return array<string>
     */
    private function convertComponentData(string $arrayString, string $component): array
    {
        try {
            /** @throws ParserError */
            return $this->arrayStringToArrayConverter->convert($arrayString);
        } catch (ParserError) {
            $this->errors[] = ["Unable to read the data passed to component [{$component}].", 'bladestan.parsing'];

            return [];
        }
    }

    /**
     * @param array<string, Type> $variablesAndTypes
     */
    private function decoratePhpContent(string $phpCode, array $variablesAndTypes): string
    {
        $stmts = array_merge(
            $this->varDocNodeFactory->createDocNodes($variablesAndTypes + $this->shared),
            $this->simplePhpParser->parse($phpCode),
        );

        return $this->printerStandard->prettyPrintFile($stmts) . PHP_EOL;
    }

    /**
     * Decorate compiled PHP with @var annotations from a TemplateSignature and
     * component-body scope (both PHPDoc type strings), plus view composer data
     * and shared variables (PHPStan Type objects).
     *
     * Precedence, highest first: the signature (the author's explicit contract),
     * then the component scope Blade injects, then view composer data, then
     * shared variables. A name declared by a higher source is not re-emitted.
     *
     * @param array<string, Type> $viewData Additional types from view composers
     * @param array<string, string> $componentScope Variables Blade adds to a component body
     */
    private function decoratePhpContentStandalone(
        string $phpCode,
        TemplateSignature $templateSignature,
        array $viewData,
        array $componentScope,
    ): string {
        $varNops = [];
        $declared = [];

        // Emit @var from signature (string-based)
        foreach ($templateSignature->variables as $name => $type) {
            $nop = new Nop();
            $nop->setDocComment(new Doc("/** @var {$type} \${$name} */"));
            $varNops[] = $nop;
            $declared[$name] = true;
        }

        // Emit @var from component-body scope (string-based). The signature wins,
        // so a variable it declares is not re-emitted.
        foreach ($componentScope as $name => $type) {
            if (isset($declared[$name])) {
                continue;
            }

            $nop = new Nop();
            $nop->setDocComment(new Doc("/** @var {$type} \${$name} */"));
            $varNops[] = $nop;
            $declared[$name] = true;
        }

        // Emit @var from view composer data (skip if already declared)
        foreach ($viewData as $name => $type) {
            if (isset($declared[$name])) {
                continue;
            }

            $typeStr = $type->describe(VerbosityLevel::typeOnly());
            $nop = new Nop();
            $nop->setDocComment(new Doc("/** @var {$typeStr} \${$name} */"));
            $varNops[] = $nop;
            $declared[$name] = true;
        }

        // Emit @var from shared variables (skip if already declared)
        foreach ($this->shared as $name => $type) {
            if (isset($declared[$name])) {
                continue;
            }

            $typeStr = $type->describe(VerbosityLevel::typeOnly());
            $nop = new Nop();
            $nop->setDocComment(new Doc("/** @var {$typeStr} \${$name} */"));
            $varNops[] = $nop;
            $declared[$name] = true;
        }

        $stmts = array_merge($varNops, $this->simplePhpParser->parse($phpCode));

        return $this->printerStandard->prettyPrintFile($stmts) . PHP_EOL;
    }

    /**
     * @param NodeVisitorAbstract[] $nodeVisitors
     * @return Node[]
     * @throws ParserError
     */
    private function traverseStmtsWithVisitors(string $phpCode, array $nodeVisitors): array
    {
        /** @throws ParserError */
        $stmts = $this->simplePhpParser->parse($phpCode);

        return $this->traverseNodesWithVisitors($stmts, $nodeVisitors);
    }

    /**
     * @param Node[] $stmts
     * @param NodeVisitorAbstract[] $nodeVisitors
     * @return Node[]
     */
    private function traverseNodesWithVisitors(array $stmts, array $nodeVisitors): array
    {
        $nodeTraverser = new NodeTraverser();
        foreach ($nodeVisitors as $nodeVisitor) {
            $nodeTraverser->addVisitor($nodeVisitor);
        }

        return $nodeTraverser->traverse($stmts);
    }

    /**
     * @return list<AbstractInlinedElement>
     */
    private function getIncludes(string $rawPhpCode): array
    {
        $return = [];

        try {
            $includeCollector = new IncludeCollector();
            $this->traverseStmtsWithVisitors("<?php\n\n" . $rawPhpCode, [$includeCollector]);
            foreach ($includeCollector->getIncludes() as $include) {
                $data = $include[2];
                $extract = null;
                if (preg_match('#^\$[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$#s', $data) === 1) {
                    $extract = $data;
                    $data = [];
                } else {
                    $data = $this->arrayStringToArrayConverter->convert($data);
                    // Filter out attributes
                    $data = array_filter($data, function (string|int $key): bool {
                        return is_string($key) && preg_match(
                            '#^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$#s',
                            $key
                        ) === 1;
                    }, ARRAY_FILTER_USE_KEY);
                }

                $data = $this->getViewDataNative($include[0]) + $data + $this->sharedNative;

                $return[] = new IncludedViewAndVariables($include[0], $include[1], $data, $extract);
            }
        } catch (ParserError) {
        }

        preg_match_all(self::COMPONENT_REGEX, $rawPhpCode, $components, PREG_SET_ORDER);
        foreach ($components as $component) {
            if ($component[1] !== AnonymousComponent::class) {
                continue;
            }

            preg_match(self::ANONYMOUS_COMPONENT_REGEX, $component[0], $matches);

            $view = $matches[1] ?? '';
            if ($view === '') {
                continue;
            }

            $includeVariables = $matches[2] ?? '[]';
            $includeVariables = $this->convertComponentData($includeVariables, $view);
            // Filter out attributes
            $includeVariables = array_filter($includeVariables, function (string|int $key): bool {
                return is_string($key) && preg_match('#^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$#s', $key) === 1;
            }, ARRAY_FILTER_USE_KEY);

            $includeVariables = $this->getViewDataNative($view) + $includeVariables + $this->sharedNative;

            $return[] = new ComponentAndVariables(
                $component[0],
                $view,
                $includeVariables,
                $this->arrayStringToArrayConverter
            );
        }

        return $return;
    }
}
