<?php

declare(strict_types=1);

namespace Bladestan\Tests\Rules\Source;

/**
 * A project's own response class that renders a view from its constructor, the
 * shape `renderSiteClasses` exists for.
 */
class SomeViewResponse
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly string $viewName,
        public readonly array $data = [],
    ) {
    }
}
