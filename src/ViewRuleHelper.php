<?php

declare(strict_types=1);

namespace TomasVotruba\Bladestan;

use PhpParser\Node\Expr\CallLike;
use PHPStan\Analyser\Error;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Registry;
use TomasVotruba\Bladestan\Compiler\BladeToPHPCompiler;
use TomasVotruba\Bladestan\ErrorReporting\Blade\TemplateErrorsFactory;
use TomasVotruba\Bladestan\TemplateCompiler\ErrorFilter;
use TomasVotruba\Bladestan\TemplateCompiler\PHPStan\FileAnalyserProvider;
use TomasVotruba\Bladestan\TemplateCompiler\TypeAnalyzer\TemplateVariableTypesResolver;
use TomasVotruba\Bladestan\TemplateCompiler\ValueObject\RenderTemplateWithParameters;
use TomasVotruba\Bladestan\TemplateCompiler\ValueObject\VariableAndType;
use TomasVotruba\Bladestan\ValueObject\CompiledTemplate;

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
     * @param RenderTemplateWithParameters[] $renderTemplatesWithParameters
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(CallLike $callLike, Scope $scope, array $renderTemplatesWithParameters): array
    {
        $ruleErrors = [];
        foreach ($renderTemplatesWithParameters as $renderTemplateWithParameter) {
            $variablesAndTypes = $this->templateVariableTypesResolver->resolveArray(
                $renderTemplateWithParameter->getParametersArray(),
                $scope
            );

            $compiledTemplate = $this->compileToPhp(
                $renderTemplateWithParameter->getTemplateFilePath(),
                $variablesAndTypes,
                $scope->getFile(),
                $callLike->getLine()
            );

            if (! $compiledTemplate instanceof CompiledTemplate) {
                continue;
            }

            $currentRuleErrors = $this->processTemplateFilePath($compiledTemplate);

            $ruleErrors = array_merge($ruleErrors, $currentRuleErrors);
        }

        return $ruleErrors;
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
            $compiledTemplate->getPhpFilePath(),
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
            $compiledTemplate->getPhpLine(),
            $compiledTemplate->getBladeFilePath(),
            $compiledTemplate->getLineMap(),
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

        $phpFileContents = $phpFileContentsWithLineMap->getPhpFileContents();

        $tmpFilePath = sys_get_temp_dir() . '/' . md5($filePath) . '-blade-compiled.php';
        file_put_contents($tmpFilePath, $phpFileContents);

        return new CompiledTemplate($filePath, $tmpFilePath, $phpFileContentsWithLineMap, $phpLine);
    }
}
