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
     * @var array{template_paths: list<string>}
     */
    private array $parameters = [
        self::TEMPLATE_PATHS => [],
    ];

    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(array $parameters)
    {
        Assert::keyExists($parameters, self::TEMPLATE_PATHS);
        $templatePaths = $parameters[self::TEMPLATE_PATHS];
        Assert::isArray($templatePaths);
        foreach ($templatePaths as $templatePath) {
            Assert::true(is_string($templatePath));
            $this->parameters[self::TEMPLATE_PATHS][] = $templatePath;
        }
    }

    /**
     * @return list<string>
     */
    public function getTemplatePaths(): array
    {
        return $this->parameters[self::TEMPLATE_PATHS];
    }
}
