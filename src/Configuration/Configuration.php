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

    public const NAMESPACED_PATHS = 'namespaced_paths';

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

        $namespacedPaths = $this->parameters[self::NAMESPACED_PATHS] ?? [];
        Assert::isArray($namespacedPaths);
        Assert::allString($namespacedPaths);
    }

    /**
     * @return string[]
     */
    public function getTemplatePaths(): array
    {
        return $this->parameters[self::TEMPLATE_PATHS];
    }

    /**
     * @return array<string, string>
     */
    public function getNamespacedPaths(): array
    {
        return $this->parameters[self::NAMESPACED_PATHS] ?? [];
    }
}
