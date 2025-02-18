<?php

declare(strict_types=1);

namespace Bladestan\Compiler;

use Bladestan\Blade\PhpLineToTemplateLineResolver;
use Bladestan\Exception\ShouldNotHappenException;
use Bladestan\NodeAnalyzer\ValueResolver;
use Bladestan\PhpParser\ArrayStringToArrayConverter;
use Bladestan\PhpParser\NodeVisitor\AddLoopVarTypeToForeachNodeVisitor;
use Bladestan\PhpParser\NodeVisitor\DeleteInlineHTML;
use Bladestan\PhpParser\NodeVisitor\TransformEach;
use Bladestan\PhpParser\NodeVisitor\TransformIncludes;
use Bladestan\PhpParser\SimplePhpParser;
use Bladestan\TemplateCompiler\NodeFactory\VarDocNodeFactory;
use Bladestan\ValueObject\AbstractInlinedElement;
use Bladestan\ValueObject\ComponentAndVariables;
use Bladestan\ValueObject\IncludedViewAndVariables;
use Bladestan\ValueObject\PhpFileContentsWithLineMap;
use Bladestan\ValueObject\ViewDataCollector;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ViewErrorBag;
use Illuminate\View\AnonymousComponent;
use Illuminate\View\Compilers\BladeCompiler;
use InvalidArgumentException;
use PhpParser\Error as ParserError;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter\Standard;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use ReflectionClass;
use ReflectionNamedType;
use Throwable;

final class BladeToPHPCompiler
{
    /**
     * @see https://regex101.com/r/dpKuqR/1
     * @var string
     */
    private const VIEW_INCLUDE_REGEX = '/echo \$__env->make\( *\'([^\']+?)\' *(?:, *(\[.*?\]|\$[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*))?.*?\)->render\(\);/s';

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
    private const BACKED_COMPONENT_REGEX = '/if \(isset\(\$component\)\).+?\$component = (.*?)::resolve\((\[(?:.*?)?\]) .+?\$component->withAttributes\(\[.*?\]\);/s';

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
     * @param array<string, Type> $parametersArray
     */
    public function compileContent(
        string $resolvedTemplateFilePath,
        string $fileContents,
        array $parametersArray
    ): PhpFileContentsWithLineMap {
        $this->errors = [];

        $variablesAndTypes = $this->getViewData($resolvedTemplateFilePath)
            + $parametersArray;

        $rawPhpContent = "<?php\n\n" . $this->inlineInclude(
            $resolvedTemplateFilePath,
            $fileContents,
            array_keys($variablesAndTypes)
        );
        $rawPhpContent = $this->resolveComponents($rawPhpContent);
        $rawPhpContent = $this->bubbleUpImports($rawPhpContent);

        $rawPhpContent = $this->decoratePhpContent($rawPhpContent, $variablesAndTypes);

        $phpLinesToTemplateLines = $this->phpLineToTemplateLineResolver->resolve($rawPhpContent);
        return new PhpFileContentsWithLineMap($rawPhpContent, $phpLinesToTemplateLines, $this->errors);
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
        $viewDataCollector = new ViewDataCollector($viewName);
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
            /** @throws ParserError */
            $stmts = $this->simplePhpParser->parse($compiledBlade);
            $stmts = $this->traverseStmtsWithVisitors($stmts, [
                new DeleteInlineHTML(),
                new AddLoopVarTypeToForeachNodeVisitor(),
                new TransformEach(),
                new TransformIncludes(),
            ]);
            $rawPhpContent = $this->printerStandard->prettyPrint($stmts) . "\n";
        } catch (ParserError) {
            $filePath = $this->fileNameAndLineNumberAddingPreCompiler->getRelativePath($filePath);
            $this->errors[] = ["View [{$filePath}] contains syntx errors.", 'bladestan.parsing'];
        } catch (InvalidArgumentException $exception) {
            $this->errors[] = [$exception->getMessage(), 'bladestan.missing'];
        }

        $rawPhpContent = $this->livewireTagCompiler->replace($rawPhpContent);

        // Recursively fetch and compile includes
        foreach ($this->getIncludes($rawPhpContent) as $include) {
            try {
                /** @throws InvalidArgumentException */
                $includedFilePath = $this->viewFactory->getFinder()
                    ->find($include->includedViewName);
                $includedContent = $this->fileSystem->get($includedFilePath);
            } catch (InvalidArgumentException|FileNotFoundException $exception) {
                $includedFilePath = '';
                $includedContent = '';
                $this->errors[] = [$exception->getMessage(), 'bladestan.missing'];
            }

            $includedContent = $include->preprocessTemplate($includedContent, array_keys($this->shared));
            $includedContent = $this->inlineInclude(
                $includedFilePath,
                $includedContent,
                $include->getInnerScopeVariableNames($allVariablesList)
            );

            $rawPhpContent = str_replace(
                $include->rawPhpContent,
                $include->generateInlineRepresentation($includedContent),
                $rawPhpContent
            );
        }

        return $rawPhpContent;
    }

    private function bubbleUpImports(string $rawPhpContent): string
    {
        preg_match_all('/(?<=^|\s)use .+?;/', $rawPhpContent, $imports);
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
            $attributes = $this->arrayStringToArrayConverter->convert($arrayString);

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
     * @param array<string, Type> $variablesAndTypes
     */
    private function decoratePhpContent(string $phpContent, array $variablesAndTypes): string
    {
        $stmts = array_merge(
            $this->varDocNodeFactory->createDocNodes($variablesAndTypes + $this->shared),
            $this->simplePhpParser->parse($phpContent),
        );

        return $this->printerStandard->prettyPrintFile($stmts) . PHP_EOL;
    }

    /**
     * @param Stmt[] $stmts
     * @param NodeVisitorAbstract[] $nodeVisitors
     * @return Node[]
     */
    private function traverseStmtsWithVisitors(array $stmts, array $nodeVisitors): array
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
    private function getIncludes(string $compiled): array
    {
        $return = [];

        preg_match_all(self::VIEW_INCLUDE_REGEX, $compiled, $includes, PREG_SET_ORDER);
        foreach ($includes as $include) {
            $data = $include[2] ?? '';
            $extract = null;
            if (preg_match('#^\$[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$#s', $data) === 1) {
                $extract = $data;
                $data = [];
            } else {
                $data = $this->arrayStringToArrayConverter->convert($data);
                // Filter out attributes
                $data = array_filter($data, function (string|int $key): bool {
                    return is_string($key) && preg_match('#^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$#s', $key) === 1;
                }, ARRAY_FILTER_USE_KEY);
            }

            $data = $this->getViewDataNative($include[0]) + $data + $this->sharedNative;

            $return[] = new IncludedViewAndVariables($include[0], $include[1], $data, $extract);
        }

        preg_match_all(self::COMPONENT_REGEX, $compiled, $components, PREG_SET_ORDER);
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
            $includeVariables = $this->arrayStringToArrayConverter->convert($includeVariables);
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
