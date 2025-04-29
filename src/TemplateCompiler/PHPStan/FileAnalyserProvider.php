<?php

declare(strict_types=1);

namespace Bladestan\TemplateCompiler\PHPStan;

use Bladestan\TemplateCompiler\Rules\TemplateRulesRegistry;
use PhpParser\Node;
use PHPStan\Analyser\FileAnalyser;
use PHPStan\DependencyInjection\DerivativeContainerFactory;
use PHPStan\Rules\Rule;

/**
 * This file analyser creates custom PHPStan DI container, based on rich php-parser with parent connection etc.
 *
 * It allows full analysis of just-in-time PHP files since PHPStan 1.0
 */
final class FileAnalyserProvider
{
    private TemplateRulesRegistry|null $templateRulesRegistry = null;

    private FileAnalyser|null $fileAnalyser = null;

    public function __construct(
        private readonly DerivativeContainerFactory $derivativeContainerFactory
    ) {
    }

    public function getRules(): TemplateRulesRegistry
    {
        if ($this->templateRulesRegistry instanceof TemplateRulesRegistry) {
            return $this->templateRulesRegistry;
        }

        /** @phpstan-ignore phpstanApi.method */
        $container = $this->derivativeContainerFactory->create([]);
        /** @var array<Rule<Node>> */
        $rules = $container->getServicesByTag('phpstan.rules.rule');
        $rules = new TemplateRulesRegistry($rules);

        $this->templateRulesRegistry = $rules;

        return $rules;
    }

    public function provide(): FileAnalyser
    {
        /** @phpstan-ignore phpstanApi.class */
        if ($this->fileAnalyser instanceof FileAnalyser) {
            return $this->fileAnalyser;
        }

        /** @phpstan-ignore phpstanApi.method */
        $container = $this->derivativeContainerFactory->create(
            [__DIR__ . '/../../../config/template-compiler/php-parser.neon']
        );

        /** @phpstan-ignore phpstanApi.classConstant */
        $fileAnalyser = $container->getByType(FileAnalyser::class);
        $this->fileAnalyser = $fileAnalyser;

        return $fileAnalyser;
    }
}
