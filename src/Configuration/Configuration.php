<?php

declare(strict_types=1);

namespace Bladestan\Configuration;

use Webmozart\Assert\Assert;

final class Configuration
{
    /**
     * @var string
     */
    public const TEMPLATE_PATHS = 'template_paths';

    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        private readonly array $parameters
    ) {
        Assert::keyExists($this->parameters, self::TEMPLATE_PATHS);

        $templatePaths = $this->parameters[self::TEMPLATE_PATHS];
        Assert::isArray($templatePaths);
        Assert::allString($templatePaths);
    }

    /**
     * @return string[]
     */
    public function getTemplatePaths(): array
    {
        return $this->parameters[self::TEMPLATE_PATHS];
    }
}
