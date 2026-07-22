<?php

declare(strict_types=1);

namespace Bladestan\Compiler;

use Bladestan\Blade\PhpLineToTemplateLineResolver;
use Bladestan\Exception\ShouldNotHappenException;
use Bladestan\NodeAnalyzer\ValueResolver;
use Bladestan\PhpParser\ArrayStringToArrayConverter;
use Bladestan\PhpParser\NodeVisitor\AddLoopVarTypeToForeachNodeVisitor;
use Bladestan\PhpParser\NodeVisitor\DeleteInlineHTML;
use Bladestan\PhpParser\NodeVisitor\RemoveLivewireCompilerArtifacts;
use Bladestan\PhpParser\NodeVisitor\TransformEach;
use Bladestan\PhpParser\NodeVisitor\TransformIncludes;
use Bladestan\PhpParser\NodeVisitor\TransformIncludesToViewCalls;
use Bladestan\PhpParser\SimplePhpParser;
use Bladestan\ValueObject\PhpFileContentsWithLineMap;
use Bladestan\ValueObject\TemplateSignature;
use Bladestan\ValueObject\ViewDataCollector;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Support\ViewErrorBag;
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
    private const COMPILED_OUTPUT_VERSION = 1;

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
     * @var list<array{0: string, 1: string}>
     */
    private array $errors;

    /**
     * @var array<string, Type>
     */
    private readonly array $shared;

    private readonly ViewFactory $viewFactory;

    public function __construct(
        private readonly BladeCompiler $bladeCompiler,
        private readonly Standard $printerStandard,
        private readonly ValueResolver $valueResolver,
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
        foreach ($this->viewFactory->getShared() as $name => $value) {
            $shared[(string) $name] = $this->valueResolver->resolve($value);
        }

        $this->shared = $shared;
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
            $sharedTypes,
        ]));
    }

    /**
     * Hash of this template's compilation inputs that live outside its own
     * source text: the component-body scope (which reflects a backing
     * component/Livewire class's public members) and any view composer data.
     * Neither is visible to TemplateCompilationBootstrap's per-file source
     * hash, since changing `App\View\Components\Panel::$heading` or a
     * composer's provided value doesn't touch the .blade.php file at all.
     * The bootstrap calls this on every run, even for a template whose source
     * is unchanged, and recompiles when it differs from the manifest.
     */
    public function getTemplateDependencyHash(string $viewName, string $fileContents): string
    {
        $componentScope = $this->componentScopeResolver->resolve($viewName, $fileContents);

        $viewDataTypes = [];
        foreach ($this->getViewData($viewName) as $name => $type) {
            $viewDataTypes[$name] = $type->describe(VerbosityLevel::cache());
        }

        return hash('xxh128', serialize([$componentScope, $viewDataTypes]));
    }

    /**
     * Compile a blade template standalone.
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
            $this->errors[] = ["Cannot read file: {$resolvedTemplateFilePath}", 'bladestan.io'];

            return new PhpFileContentsWithLineMap(
                "<?php\n" . $this->diagnosticHeader($resolvedTemplateFilePath),
                [],
                $this->errors,
            );
        }

        // Extract signature and strip the signature docblock (explicit or
        // implicit) so it doesn't survive into the compiled output and
        // duplicate the @var annotations we emit below. Each strip preserves
        // line count (the removed block becomes blank lines) so the following
        // template lines keep their original numbers, which is what the
        // line-comment pass below reports errors against.
        $templateSignature = $this->signatureExtractor->extract($fileContents);
        $fileContents = $this->signatureExtractor->stripSignatureBlock($fileContents, preserveLineCount: true);
        $fileContents = $this->signatureExtractor->stripImplicitSignatureBlock($fileContents, preserveLineCount: true);

        // Enforced parent requirements at the child's call sites via signature merging.
        $fileContents = $this->signatureExtractor->stripExtends($fileContents, preserveLineCount: true);

        // Variables Blade injects into a component body ($attributes, $slot,
        // $componentName, @props). Read before compilation, since compiling
        // consumes the @props directive. Empty for non-component templates.
        $componentScope = $this->componentScopeResolver->resolve($viewName, $fileContents);

        // Get view composer data
        $viewData = $this->getViewData($viewName);

        // Compile blade to PHP. @include directives become view() calls that
        // ViewCallSiteRule validates against the included template's own signature.
        $phpCode = "<?php\n\n" . $this->compile($resolvedTemplateFilePath, $fileContents);
        $phpCode = $this->resolveComponents($phpCode);

        // Decorate with @var annotations from signature + shared variables
        $phpCode = $this->decoratePhpContentStandalone($phpCode, $templateSignature, $viewData, $componentScope);

        // Add source tracking header (and any compile-error markers) after the
        // <?php tag. This runs before the line map is resolved below so the map
        // accounts for the header lines.
        $phpCode = preg_replace('/^<\?php\n/', "<?php\n" . $this->diagnosticHeader($resolvedTemplateFilePath), $phpCode)
            ?? $phpCode;

        $phpLinesToTemplateLines = $this->phpLineToTemplateLineResolver->resolve($phpCode);

        return new PhpFileContentsWithLineMap($phpCode, $phpLinesToTemplateLines, $this->errors);
    }

    /**
     * Build the comment header prepended to every compiled file: the
     * `@bladestan-source` back-reference plus one `@bladestan-error` marker per
     * collected compile failure.
     *
     * The markers persist the errors that would otherwise be lost once the
     * compiled PHP is written to disk. A parse failure compiles to an empty
     * shell that PHPStan analyzes cleanly, so without a durable signal the
     * template silently drops out of analysis. TemplateCompilationErrorRule
     * reads these markers back and reports them against the .blade.php file.
     * The payload is JSON so a multi-line message stays on one comment line.
     */
    private function diagnosticHeader(string $resolvedTemplateFilePath): string
    {
        $header = "// @bladestan-source: {$resolvedTemplateFilePath}\n";

        foreach ($this->errors as [$message, $identifier]) {
            $header .= $this->errorMarker($message, $identifier);
        }

        return $header;
    }

    /**
     * A self-contained compiled shell for a template that threw during
     * compilation, so it still carries a signal instead of dropping out of
     * analysis. Valid PHP (comments only), matching the header format above.
     */
    public function errorStub(string $resolvedTemplateFilePath, string $message, string $identifier): string
    {
        return "<?php\n// @bladestan-source: {$resolvedTemplateFilePath}\n" . $this->errorMarker($message, $identifier);
    }

    /**
     * One `@bladestan-error` comment marker. The payload is JSON so a multi-line
     * message stays on a single comment line; TemplateCompilationErrorRule reads
     * it back and reports it against the .blade.php file.
     */
    private function errorMarker(string $message, string $identifier): string
    {
        $payload = json_encode([
            'message' => $message,
            'identifier' => $identifier,
        ], JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return '';
        }

        return "// @bladestan-error: {$payload}\n";
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
     * Compile a single blade template to PHP.
     * Includes become view() call sites.
     */
    private function compile(string $filePath, string $fileContents): string
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
            $this->errors[] = ["View [{$relativeFilePath}] contains syntax errors.", 'bladestan.parsing'];
        } catch (InvalidArgumentException $invalidArgumentException) {
            $this->errors[] = [$invalidArgumentException->getMessage(), 'bladestan.missing'];
        }

        return $this->livewireTagCompiler->replace($rawPhpContent);
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
}
