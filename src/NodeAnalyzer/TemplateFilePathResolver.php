<?php

declare(strict_types=1);

namespace Bladestan\NodeAnalyzer;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\View\ViewName;
use InvalidArgumentException;

final class TemplateFilePathResolver
{
    public function __construct(
        private readonly ViewFactory $viewFactory,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     */
    public function resolveExistingFilePath(string $resolvedValue): string
    {
        $resolvedValue = ViewName::normalize($resolvedValue);

        /** @throws InvalidArgumentException */
        $view = $this->viewFactory->getFinder()
            ->find($resolvedValue);

        return $view;
    }
}
