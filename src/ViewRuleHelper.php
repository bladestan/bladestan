<?php

declare(strict_types=1);

namespace Bladestan;

use Bladestan\Compiler\BladeToPHPCompiler;
use Bladestan\ErrorReporting\Blade\TemplateErrorsFactory;
use Bladestan\TemplateCompiler\ErrorFilter;
use Bladestan\TemplateCompiler\PHPStan\FileAnalyserProvider;
use Bladestan\TemplateCompiler\TypeAnalyzer\TemplateVariableTypesResolver;
use Bladestan\TemplateCompiler\ValueObject\RenderTemplateWithParameters;
use Bladestan\TemplateCompiler\ValueObject\VariableAndType;
use Bladestan\ValueObject\CompiledTemplate;
use PhpParser\Node\Expr\CallLike;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Registry;

final class ViewRuleHelper
{
    private Registry $registry;

    public function __construct(
        private readonly TemplateVariableTypesResolver $templateVariableTypesResolver,
        private readonly FileAnalyserProvider $fileAnalyserProvider,
        private readonly TemplateErrorsFactory $templateErrorsFactory,
        private readonly BladeToPHPCompiler $bladeToPhpCompiler,
        private readonly ErrorFilter $errorFilter,
    ) {
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(
        CallLike $callLike,
        Scope $scope,
        RenderTemplateWithParameters $renderTemplateWithParameters
    ): array {
        $variablesAndTypes = $this->templateVariableTypesResolver->resolveArray(
            $renderTemplateWithParameters->parametersArray,
            $scope
        );

        $compiledTemplate = $this->compileToPhp(
            $renderTemplateWithParameters->templateFilePath,
            $variablesAndTypes,
            $scope->getFile(),
            $callLike->getLine()
        );

        if (! $compiledTemplate instanceof CompiledTemplate) {
            return [];
        }

        return $this->processTemplateFilePath($compiledTemplate);
    }

    public function setRegistry(Registry $registry): void
    {
        $this->registry = $registry;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function processTemplateFilePath(CompiledTemplate $compiledTemplate): array
    {
        $fileAnalyser = $this->fileAnalyserProvider->provide();

        /** @phpstan-ignore phpstanApi.constructor */
        $collectorsRegistry = new \PHPStan\Collectors\Registry([]);

        /** @phpstan-ignore phpstanApi.method */
        $fileAnalyserResult = $fileAnalyser->analyseFile(
            $compiledTemplate->phpFilePath,
            [],
            $this->registry,
            $collectorsRegistry,
            null
        );

        /** @phpstan-ignore phpstanApi.method */
        $ruleErrors = $fileAnalyserResult->getErrors();

        $usefulRuleErrors = $this->errorFilter->filterErrors($ruleErrors);

        return $this->templateErrorsFactory->createErrors(
            $usefulRuleErrors,
            $compiledTemplate->phpLine,
            $compiledTemplate->bladeFilePath,
            $compiledTemplate->phpFileContentsWithLineMap,
        );
    }

    /**
     * @param VariableAndType[] $variablesAndTypes
     */
    private function compileToPhp(
        string $templateFilePath,
        array $variablesAndTypes,
        string $filePath,
        int $phpLine
    ): ?CompiledTemplate {
        $fileContents = file_get_contents($templateFilePath);
        if ($fileContents === false) {
            return null;
        }

        $phpFileContentsWithLineMap = $this->bladeToPhpCompiler->compileContent(
            $templateFilePath,
            $fileContents,
            $variablesAndTypes
        );

        $phpFileContents = $phpFileContentsWithLineMap->phpFileContents;

        $tmpFilePath = sys_get_temp_dir() . '/' . md5($filePath) . '-blade-compiled.php';
        file_put_contents($tmpFilePath, $phpFileContents);

        return new CompiledTemplate($filePath, $tmpFilePath, $phpFileContentsWithLineMap, $phpLine);
    }
}
