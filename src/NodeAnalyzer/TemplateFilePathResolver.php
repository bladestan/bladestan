<?php

declare(strict_types=1);

namespace Bladestan\NodeAnalyzer;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\View\ViewFinderInterface;
use InvalidArgumentException;
use PhpParser\Node\Expr;
use PHPStan\Analyser\Scope;

final class TemplateFilePathResolver
{
    public function __construct(
        private readonly ViewFactory $viewFactory,
        private readonly ValueResolver $valueResolver,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     */
    public function resolveExistingFilePath(Expr $expr, Scope $scope): ?string
    {
        $resolvedValue = $this->valueResolver->resolve($expr, $scope);

        if (! is_string($resolvedValue)) {
            return null;
        }

        $resolvedValue = $this->normalizeName($resolvedValue);

        if (file_exists($resolvedValue)) {
            return $resolvedValue;
        }

        /** @throws InvalidArgumentException */
        $view = $this->viewFactory->getFinder()
            ->find($resolvedValue);

        return $view;
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
}
