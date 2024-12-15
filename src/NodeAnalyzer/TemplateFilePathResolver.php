<?php

declare(strict_types=1);

namespace Bladestan\NodeAnalyzer;

use Illuminate\View\FileViewFinder;
use Illuminate\View\ViewFinderInterface;
use InvalidArgumentException;
use PhpParser\Node\Expr;
use PHPStan\Analyser\Scope;

final class TemplateFilePathResolver
{
    public function __construct(
        private readonly FileViewFinder $fileViewFinder,
        private readonly ValueResolver $valueResolver,
    ) {
    }

    /**
     * @return string[]
     */
    public function resolveExistingFilePaths(Expr $expr, Scope $scope): array
    {
        $resolvedValue = $this->valueResolver->resolve($expr, $scope);

        if (! is_string($resolvedValue)) {
            return [];
        }

        $resolvedValue = $this->normalizeName($resolvedValue);

        if (file_exists($resolvedValue)) {
            return [$resolvedValue];
        }

        $view = $this->findView($resolvedValue);

        if ($view === null) {
            return [];
        }

        return [$view];
    }

    private function normalizeName(string $name): string
    {
        $delimiter = ViewFinderInterface::HINT_PATH_DELIMITER;

        if (! str_contains($name, $delimiter)) {
            return str_replace('/', '.', $name);
        }

        [$namespace, $name] = explode($delimiter, $name);

        return str_replace('/', '.', $name);
    }

    private function findView(string $view): ?string
    {
        try {
            return $this->fileViewFinder->find($view);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
