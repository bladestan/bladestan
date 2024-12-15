<?php

declare(strict_types=1);

namespace Bladestan\ValueObject;

final class CompiledTemplate
{
    public function __construct(
        private readonly string $bladeFilePath,
        private readonly string $phpFilePath,
        private readonly PhpFileContentsWithLineMap $phpFileContentsWithLineMap,
        private readonly int $phpLine,
    ) {
    }

    public function getBladeFilePath(): string
    {
        return $this->bladeFilePath;
    }

    public function getPhpFilePath(): string
    {
        return $this->phpFilePath;
    }

    public function getLineMap(): PhpFileContentsWithLineMap
    {
        return $this->phpFileContentsWithLineMap;
    }

    public function getPhpLine(): int
    {
        return $this->phpLine;
    }
}
