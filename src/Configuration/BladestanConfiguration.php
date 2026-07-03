<?php

declare(strict_types=1);

namespace Bladestan\Configuration;

/**
 * Wraps Bladestan's neon configuration parameters into a typed value object.
 */
final class BladestanConfiguration
{
    public function __construct(
        private readonly string $compiledViewPath,
    ) {
    }

    /**
     * The directory where compiled blade templates are written.
     * Defaults to `%tmpDir%/bladestan` (set in extension.neon).
     */
    public function getCompiledViewPath(): string
    {
        return $this->compiledViewPath;
    }
}
