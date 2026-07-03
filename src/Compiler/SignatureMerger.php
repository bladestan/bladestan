<?php

declare(strict_types=1);

namespace Bladestan\Compiler;

use Bladestan\NodeAnalyzer\TemplateFilePathResolver;
use Bladestan\ValueObject\TemplateSignature;
use InvalidArgumentException;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\Type\Type;

/**
 * Merges template signatures from `@extends` chains and backed component classes.
 *
 * Follows covariance rules (same as readonly property variance in PHP):
 * - A child template may **narrow** a parent's type (e.g. `?string` → `string`)
 * - A child template may NOT **widen** a parent's type (e.g. `string` → `string|int`)
 * - Incompatible types are an error
 *
 * The merge is only used for call-site validation. Each template is still
 * analyzed independently against its own signature.
 *
 * @see \Bladestan\Tests\Compiler\SignatureMergerTest
 */
final class SignatureMerger
{
    /**
     * Maximum @extends chain depth to prevent infinite loops.
     */
    private const MAX_EXTENDS_DEPTH = 20;

    /**
     * Memoized merged signatures + their merge errors. A rule instance lives
     * for the whole analysis run and templates don't change mid-run, so every
     * call site of the same template can reuse one merge (and its file reads).
     *
     * @var array<string, array{TemplateSignature, list<string>}>
     */
    private array $mergedSignatureCache = [];

    /**
     * Memoized per-file signatures (no @extends walking).
     *
     * @var array<string, TemplateSignature>
     */
    private array $ownSignatureCache = [];

    public function __construct(
        private readonly SignatureExtractor $signatureExtractor,
        private readonly TemplateFilePathResolver $templateFilePathResolver,
        private readonly TypeStringResolver $typeStringResolver,
    ) {
    }

    /**
     * The template's own signature, without walking the @extends chain.
     * Memoized across call sites.
     */
    public function ownSignature(string $bladeFilePath): TemplateSignature
    {
        if (! isset($this->ownSignatureCache[$bladeFilePath])) {
            $content = @file_get_contents($bladeFilePath);
            $this->ownSignatureCache[$bladeFilePath] = $content === false
                ? new TemplateSignature([])
                : $this->signatureExtractor->extract($content);
        }

        return $this->ownSignatureCache[$bladeFilePath];
    }

    /**
     * Resolve the merged signature for a template, walking its @extends chain.
     *
     * The result is the union of all variables from the child and all ancestors,
     * with covariance rules applied on conflicts.
     *
     * @param list<string> $errors Collects error messages for covariance violations
     * @return TemplateSignature The merged signature for call-site validation
     */
    public function mergeForTemplate(string $bladeFilePath, array &$errors = []): TemplateSignature
    {
        if (isset($this->mergedSignatureCache[$bladeFilePath])) {
            [$cachedSignature, $cachedErrors] = $this->mergedSignatureCache[$bladeFilePath];
            $errors = array_merge($errors, $cachedErrors);

            return $cachedSignature;
        }

        $mergeErrors = [];
        $templateSignature = $this->doMergeForTemplate($bladeFilePath, $mergeErrors);
        $this->mergedSignatureCache[$bladeFilePath] = [$templateSignature, $mergeErrors];
        $errors = array_merge($errors, $mergeErrors);

        return $templateSignature;
    }

    /**
     * @param list<string> $errors
     */
    private function doMergeForTemplate(string $bladeFilePath, array &$errors): TemplateSignature
    {
        $chain = $this->resolveExtendsChain($bladeFilePath);

        if (count($chain) <= 1) {
            // No @extends chain — return the template's own signature
            return $chain[0]['signature'];
        }

        // Merge pairwise from the deepest ancestor up to the child.
        // chain[0] is the child, chain[N] is the deepest ancestor.
        $merged = $chain[count($chain) - 1]['signature'];

        for ($i = count($chain) - 2; $i >= 0; $i--) {
            $childSig = $chain[$i]['signature'];
            $childPath = $chain[$i]['path'];
            $parentPath = $chain[$i + 1]['path'];

            $merged = $this->mergePair($childSig, $merged, $childPath, $parentPath, $errors);
        }

        return $merged;
    }

    /**
     * Merge a child signature with a parent signature, applying covariance rules.
     *
     * @param list<string> $errors
     */
    private function mergePair(
        TemplateSignature $child,
        TemplateSignature $parent,
        string $childPath,
        string $parentPath,
        array &$errors,
    ): TemplateSignature {
        $merged = [];

        $allVarNames = array_unique(array_merge(array_keys($child->variables), array_keys($parent->variables)));

        foreach ($allVarNames as $allVarName) {
            $childType = $child->variables[$allVarName] ?? null;
            $parentType = $parent->variables[$allVarName] ?? null;

            if ($childType !== null && $parentType === null) {
                // Only in child
                $merged[$allVarName] = $childType;
                continue;
            }

            if ($childType === null && $parentType !== null) {
                // Only in parent
                $merged[$allVarName] = $parentType;
                continue;
            }

            // In both — apply covariance check
            assert($childType !== null && $parentType !== null);

            if ($childType === $parentType) {
                // Identical type strings — no conflict
                $merged[$allVarName] = $childType;
                continue;
            }

            $childParsed = $this->parseTypeString($childType);
            $parentParsed = $this->parseTypeString($parentType);

            $childIsSubtype = $parentParsed->isSuperTypeOf($childParsed)
                ->yes();
            $parentIsSubtype = $childParsed->isSuperTypeOf($parentParsed)
                ->yes();

            if ($childIsSubtype) {
                // Child narrows parent — allowed (covariant). Use child's type.
                $merged[$allVarName] = $childType;
            } elseif ($parentIsSubtype) {
                // Child widens parent — forbidden
                $childBasename = basename($childPath);
                $parentBasename = basename($parentPath);
                $errors[] = sprintf(
                    'Template %s declares $%s as %s, but extended template %s declares it as %s. '
                    . 'Child templates may narrow types but not widen them.',
                    $childBasename,
                    $allVarName,
                    $childType,
                    $parentBasename,
                    $parentType,
                );
                // Use the parent's (narrower) type for call-site validation
                // so the call site must at least satisfy the parent
                $merged[$allVarName] = $parentType;
            } else {
                // Incompatible types
                $childBasename = basename($childPath);
                $parentBasename = basename($parentPath);
                $errors[] = sprintf(
                    'Template %s declares $%s as %s, which is incompatible with %s in extended template %s.',
                    $childBasename,
                    $allVarName,
                    $childType,
                    $parentType,
                    $parentBasename,
                );
                // Use the child's type for call-site validation
                $merged[$allVarName] = $childType;
            }
        }

        return new TemplateSignature($merged, $child->isExplicit || $parent->isExplicit);
    }

    /**
     * Walk the @extends chain starting from the given blade file.
     *
     * @return list<array{path: string, signature: TemplateSignature}>
     *         Index 0 is the starting template (child), last index is the deepest ancestor.
     */
    private function resolveExtendsChain(string $bladeFilePath): array
    {
        $chain = [];
        $currentPath = $bladeFilePath;
        $visited = [];

        for ($depth = 0; $depth < self::MAX_EXTENDS_DEPTH; $depth++) {
            // Prevent infinite loops from circular @extends
            $realPath = realpath($currentPath) ?: $currentPath;
            if (isset($visited[$realPath])) {
                break;
            }

            $visited[$realPath] = true;

            $content = @file_get_contents($currentPath);
            if ($content === false) {
                break;
            }

            $signature = $this->signatureExtractor->extract($content);
            $chain[] = [
                'path' => $currentPath,
                'signature' => $signature,
            ];

            $parentViewName = $this->signatureExtractor->findExtends($content);
            if ($parentViewName === null) {
                break;
            }

            try {
                $parentPath = $this->templateFilePathResolver->resolveExistingFilePath($parentViewName);
            } catch (InvalidArgumentException) {
                break;
            }

            $currentPath = $parentPath;
        }

        return $chain;
    }

    /**
     * Parse a PHPDoc type string into a PHPStan Type object.
     *
     * Uses TypeStringResolver which does not require a file context,
     * unlike FileTypeMapper which cannot resolve types for .blade.php files.
     */
    private function parseTypeString(string $typeString): Type
    {
        return $this->typeStringResolver->resolve($typeString);
    }
}
