<?php

declare(strict_types=1);

namespace TomasVotruba\Bladestan\Compiler;

use Illuminate\Contracts\View\Factory;
use Illuminate\Events\Dispatcher;
use Illuminate\Events\NullDispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\FileViewFinder;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter\Standard;
use Throwable;
use TomasVotruba\Bladestan\Blade\PhpLineToTemplateLineResolver;
use TomasVotruba\Bladestan\PhpParser\ArrayStringToArrayConverter;
use TomasVotruba\Bladestan\PhpParser\NodeVisitor\AddLoopVarTypeToForeachNodeVisitor;
use TomasVotruba\Bladestan\PhpParser\NodeVisitor\RemoveEnvVariableNodeVisitor;
use TomasVotruba\Bladestan\PhpParser\SimplePhpParser;
use TomasVotruba\Bladestan\TemplateCompiler\NodeFactory\VarDocNodeFactory;
use TomasVotruba\Bladestan\TemplateCompiler\ValueObject\VariableAndType;
use TomasVotruba\Bladestan\ValueObject\IncludedViewAndVariables;
use TomasVotruba\Bladestan\ValueObject\PhpFileContentsWithLineMap;
use Webmozart\Assert\Assert;

final class BladeToPHPCompiler
{
    /**
     * @see https://regex101.com/r/2GrCYu/1
     * @var string
     */
    private const VIEW_INCLUDE_REGEX = '/\$__env->make\( *\'(.*?)\' *, *(\[(.*?)?\] *,|\$[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*? *,)? *\\\\Illuminate\\\\Support\\\\Arr::except\( *get_defined_vars\(\) *, *\[ *\'__data\' *, *\'__path\' *] *\) *\)->render\(\)/s';

    /**
     * @see https://regex101.com/r/2GrCYu/1
     * @var string
     */
    private const VIEW_INCLUDE_REPLACE_REGEX = '/echo \$__env->make\( *\'%s\' *, *(\[(.*?)?\] *,|\$[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*? *,)? *\\\\Illuminate\\\\Support\\\\Arr::except\( *get_defined_vars\(\) *, *\[ *\'__data\' *, *\'__path\' *] *\) *\)->render\(\);/s';

    /**
     * @var string
     */
    private const USE_PLACEHOLDER = 'use(%s)';

    /**
     * @var string
     */
    private const INCLUDED_CONTENT_PLACE_HOLDER = <<<STRING
(function () %s {
%s
%s
});
STRING;

    /**
     * @param array<int, array{class: string, alias: string, prefix: string}> $components
     */
    public function __construct(
        private readonly Filesystem $fileSystem,
        private readonly BladeCompiler $bladeCompiler,
        private readonly Standard $printerStandard,
        private readonly VarDocNodeFactory $varDocNodeFactory,
        private readonly FileViewFinder $fileViewFinder,
        private readonly PhpLineToTemplateLineResolver $phpLineToTemplateLineResolver,
        private readonly PhpContentExtractor $phpContentExtractor,
        private readonly ArrayStringToArrayConverter $arrayStringToArrayConverter,
        private readonly FileNameAndLineNumberAddingPreCompiler $fileNameAndLineNumberAddingPreCompiler,
        private readonly SimplePhpParser $simplePhpParser,
        private readonly array $components = [],
    ) {
        // Disable component rendering
        // It happens before our pre-processer can intercept the compile
        $this->bladeCompiler->withoutComponentTags();
        // Replaces <livewire /> tags with arrays so attributes can be analysed
        $this->bladeCompiler->precompiler(
            fn ($string): string => (new LivewireTagCompiler($this->bladeCompiler))->compile($string)
        );
        // Replaces <x-... /> tags with arrays so attributes can be analysed
        $this->bladeCompiler->precompiler(
            fn (string $value): string => (new ComponentTagCompiler($this->bladeCompiler))->compile($value)
        );
        $this->setupBladeComponents();
    }

    /**
     * @param array<string> $allVariablesList
     */
    public function inlineInclude(
        string $filePath,
        string $fileContents,
        array $allVariablesList,
        bool $addPHPOpeningTag
    ): string {
        // Precompile contents to add template file name and line numbers
        $fileContents = $this->fileNameAndLineNumberAddingPreCompiler
            ->completeLineCommentsToBladeContents($filePath, $fileContents);

        // Extract PHP content from HTML and PHP mixed content
        $compiledBlade = $this->bladeCompiler->compileString($fileContents);
        $rawPhpContent = $this->phpContentExtractor->extract($compiledBlade, $addPHPOpeningTag);

        // Recursively fetch and compile includes
        foreach ($this->getIncludes($rawPhpContent) as $includedViewAndVariable) {
            try {
                $includedFilePath = $this->fileViewFinder->find($includedViewAndVariable->getIncludedViewName());
                $includedContent = $this->inlineInclude(
                    $includedFilePath,
                    $this->fileSystem->get($includedFilePath),
                    array_unique(
                        [...$allVariablesList, ...array_keys($includedViewAndVariable->getVariablesAndValues())]
                    ),
                    false
                );
            } catch (Throwable) {
                $includedContent = '';
            }

            $includedViewVariables = implode(
                PHP_EOL,
                array_map(
                    static fn (string $key, string $value): string => '$' . $key . ' = ' . $value . ';',
                    array_keys($includedViewAndVariable->getVariablesAndValues()),
                    $includedViewAndVariable->getVariablesAndValues()
                )
            );

            $includeVariables = $allVariablesList;
            foreach ($includedViewAndVariable->getVariablesAndValues() as $expresion) {
                preg_match_all('#\$([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]+)#s', $expresion, $variableNames);
                $includeVariables = [...$includeVariables, ...$variableNames[1]];
            }

            $usedVariablesString = implode(
                ', ',
                array_map(static fn (string $variable): string => '$' . $variable, array_unique($includeVariables))
            );
            $rawPhpContent = preg_replace(
                sprintf(self::VIEW_INCLUDE_REPLACE_REGEX, preg_quote($includedViewAndVariable->getIncludedViewName())),
                sprintf(
                    self::INCLUDED_CONTENT_PLACE_HOLDER,
                    $usedVariablesString !== '' ? sprintf(self::USE_PLACEHOLDER, $usedVariablesString) : '',
                    $includedViewVariables,
                    $includedContent
                ),
                $rawPhpContent
            ) ?? $rawPhpContent;
        }

        return $rawPhpContent;
    }

    /**
     * @param array<VariableAndType> $variablesAndTypes
     */
    public function compileContent(
        string $filePath,
        string $fileContents,
        array $variablesAndTypes
    ): PhpFileContentsWithLineMap {
        $allVariablesList = array_map(
            static fn (VariableAndType $variableAndType): string => $variableAndType->getVariable(),
            $variablesAndTypes
        );

        $rawPhpContent = $this->inlineInclude($filePath, $fileContents, $allVariablesList, true);

        $decoratedPhpContent = $this->decoratePhpContent($rawPhpContent, $variablesAndTypes);
        $phpLinesToTemplateLines = $this->phpLineToTemplateLineResolver->resolve($decoratedPhpContent);
        return new PhpFileContentsWithLineMap($decoratedPhpContent, $phpLinesToTemplateLines);
    }

    /**
     * @param VariableAndType[] $variablesAndTypes
     */
    private function decoratePhpContent(string $phpContent, array $variablesAndTypes): string
    {
        $stmts = $this->simplePhpParser->parse($phpContent);

        $this->traverseStmtsWithVisitors($stmts, [
            // get rid of $__env variables
            new RemoveEnvVariableNodeVisitor(),
            new AddLoopVarTypeToForeachNodeVisitor(),
        ]);

        // Add @var docs to top of file
        $docNodes = $this->varDocNodeFactory->createDocNodes($variablesAndTypes);
        $stmts = array_merge($docNodes, $stmts);

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
     * @return IncludedViewAndVariables[]
     */
    private function getIncludes(string $compiled): array
    {
        preg_match_all(self::VIEW_INCLUDE_REGEX, $compiled, $includes);

        $return = [];

        foreach ($includes[1] as $i => $include) {
            $arrayString = trim($includes[2][$i], ' ,');

            $array = $this->arrayStringToArrayConverter->convert($arrayString);

            $return[] = new IncludedViewAndVariables($include, $array);
        }

        return $return;
    }

    private function setupBladeComponents(): void
    {
        $currentWorkingDirectory = getcwd();

        if ($currentWorkingDirectory === false) {
            return;
        }

        $application = Application::getInstance();
        $application->bind(
            Factory::class,
            fn (): \Illuminate\View\Factory => new \Illuminate\View\Factory(
                new EngineResolver(),
                $this->fileViewFinder,
                new NullDispatcher(new Dispatcher())
            )
        );

        $application->alias('view', 'foo');

        // Register components
        foreach ($this->components as $component) {
            $this->bladeCompiler->component($component['class'], $component['alias'], $component['prefix']);
        }
    }
}
