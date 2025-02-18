<?php

declare(strict_types=1);

namespace Bladestan\NodeAnalyzer;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\View\ViewName;
use InvalidArgumentException;

final class TemplateFilePathResolver
{
    /**
     * @throws InvalidArgumentException
     */
    public function resolveExistingFilePath(string $resolvedValue): string
    {
        $resolvedValue = ViewName::normalize($resolvedValue);

        /** @throws InvalidArgumentException */
        $view = resolve(ViewFactory::class)
            ->getFinder()
            ->find($resolvedValue);

        return $view;
    }
}
