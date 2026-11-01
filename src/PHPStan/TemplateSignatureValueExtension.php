<?php

declare(strict_types=1);

namespace Bladestan\PHPStan;

use Bladestan\Compiler\SignatureExtractor;
use Bladestan\ValueObject\MergedSignature;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\ResultCache\ResultCacheValueExtension;

/**
 * The contract of one template, as PHPStan's result cache sees it: a hash of
 * the parts of the file a call site is validated against (its signature
 * docblock, its `@extends` line and its `@props`), keyed by the template's
 * absolute path.
 *
 * A file that renders a template, or extends one, declares a dependency on this
 * value for every template in the `@extends` chain. Editing a template's body
 * leaves the value alone, so only the template itself is re-analysed; changing
 * its contract re-analyses the template and the files that render or extend it,
 * and nothing else. Each file is hashed on its own rather than the merged chain,
 * so working out the value never needs a view name resolved, and with it a
 * Laravel application.
 *
 * @see \Bladestan\Tests\PHPStan\TemplateSignatureValueExtensionTest
 */
final class TemplateSignatureValueExtension implements ResultCacheValueExtension
{
    private const MISSING = 'missing';

    /**
     * Per-process memo: templates do not change during a run, and every call
     * site of a template asks for the same value.
     *
     * @var array<string, string>
     */
    private array $values = [];

    public function __construct(
        private readonly SignatureExtractor $signatureExtractor,
        private readonly string $currentWorkingDirectory,
    ) {
    }

    /**
     * Declare everything validating against $mergedSignature read: the contract
     * of every template in the chain, and the absent files whose creation would
     * resolve the chain differently.
     */
    public static function track(DependencyTracker $dependencyTracker, MergedSignature $mergedSignature): void
    {
        foreach ($mergedSignature->templateFiles as $templateFile) {
            $dependencyTracker->trackValueDependency(self::class, $templateFile);
        }

        foreach ($mergedSignature->candidateFiles as $candidateFile) {
            $dependencyTracker->trackFileDependency($candidateFile);
        }
    }

    public function getValue(string $key): string
    {
        return $this->values[$key] ??= $this->computeValue($key);
    }

    public function keyToResultCache(string $key): string
    {
        $prefix = rtrim($this->currentWorkingDirectory, '/\\') . DIRECTORY_SEPARATOR;
        if (str_starts_with($key, $prefix)) {
            return substr($key, strlen($prefix));
        }

        return $key;
    }

    public function keyFromResultCache(string $storedKey): string
    {
        if ($this->isAbsolute($storedKey)) {
            return $storedKey;
        }

        return rtrim($this->currentWorkingDirectory, '/\\') . DIRECTORY_SEPARATOR . $storedKey;
    }

    private function computeValue(string $templateFile): string
    {
        $contents = @file_get_contents($templateFile);
        if ($contents === false) {
            return self::MISSING;
        }

        return hash('xxh128', $this->signatureExtractor->extractSignatureRelevantContent($contents));
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('#^[a-zA-Z]:[/\\\\]#', $path) === 1
            || str_contains($path, '://');
    }
}
