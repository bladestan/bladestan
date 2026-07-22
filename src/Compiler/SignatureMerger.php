<?php

declare(strict_types=1);

namespace Bladestan\Compiler;

use Bladestan\NodeAnalyzer\TemplateFilePathResolver;
use Bladestan\ValueObject\MergedSignature;
use Bladestan\ValueObject\TemplateSignature;
use InvalidArgumentException;
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
     * Memoized merged signatures with their merge errors. A rule instance lives
     * for the whole analysis run and templates don't change mid-run, so every
     * call site of the same template can reuse one merge (and its file reads).
     *
     * @var array<string, MergedSignature>
     */
    private array $mergedSignatureCache = [];

    public function __construct(
        private readonly SignatureExtractor $signatureExtractor,
        private readonly TemplateFilePathResolver $templateFilePathResolver,
        private readonly TypeStringValidator $typeStringValidator,
    ) {
    }

    /**
     * Resolve the merged signature for a template, walking its @extends chain.
     *
     * The result is the union of all variables from the child and all ancestors,
     * with covariance rules applied on conflicts, alongside any errors raised
     * while merging.
     */
    public function mergeForTemplate(string $bladeFilePath): MergedSignature
    {
        return $this->mergedSignatureCache[$bladeFilePath] ??= $this->doMergeForTemplate($bladeFilePath);
    }

    private function doMergeForTemplate(string $bladeFilePath): MergedSignature
    {
        [$chain, $errors] = $this->resolveExtendsChain($bladeFilePath);

        if ($chain === []) {
            // The template became unreadable between resolution and merge
            // (deleted, permissions, a race). Degrade to an empty signature
            // rather than dereferencing a missing chain entry.
            return new MergedSignature(new TemplateSignature([]), $errors);
        }

        if (count($chain) === 1) {
            // No @extends chain — return the template's own signature
            return new MergedSignature($chain[0]['signature'], $errors);
        }

        // Merge pairwise from the deepest ancestor up to the child.
        // chain[0] is the child, chain[N] is the deepest ancestor.
        $merged = $chain[count($chain) - 1]['signature'];

        for ($i = count($chain) - 2; $i >= 0; $i--) {
            $childSig = $chain[$i]['signature'];
            $childPath = $chain[$i]['path'];
            $parentPath = $chain[$i + 1]['path'];

            [$merged, $pairErrors] = $this->mergePair($childSig, $merged, $childPath, $parentPath);
            $errors = [...$errors, ...$pairErrors];
        }

        return new MergedSignature($merged, $errors);
    }

    /**
     * Merge a child signature with a parent signature, applying covariance rules.
     *
     * @return array{TemplateSignature, list<string>} the merged signature and
     *         any covariance-violation errors raised while merging
     */
    private function mergePair(
        TemplateSignature $child,
        TemplateSignature $parent,
        string $childPath,
        string $parentPath,
    ): array {
        $merged = [];
        $errors = [];

        $allVarNames = array_unique([...array_keys($child->variables), ...array_keys($parent->variables)]);

        foreach ($allVarNames as $allVarName) {
            $childHasType = array_key_exists($allVarName, $child->variables);
            $parentHasType = array_key_exists($allVarName, $parent->variables);

            if ($childHasType && ! $parentHasType) {
                // Only in child
                $merged[$allVarName] = $child->variables[$allVarName];
                continue;
            }

            if (! $childHasType && $parentHasType) {
                // Only in parent
                $merged[$allVarName] = $parent->variables[$allVarName];
                continue;
            }

            $childType = $child->variables[$allVarName];
            $parentType = $parent->variables[$allVarName];

            // In both — apply covariance check
            if ($childType === $parentType) {
                // Identical type strings — no conflict
                $merged[$allVarName] = $childType;
                continue;
            }

            $childParsed = $this->typeStringValidator->resolve($childType);
            $parentParsed = $this->typeStringValidator->resolve($parentType);

            if (! $childParsed instanceof Type || ! $parentParsed instanceof Type) {
                // One side is not a valid PHPDoc type. The covariance check is
                // impossible, but this must not abort the run — keep the
                // child's declaration and let ViewCallSiteRule report the
                // invalid type at the call site.
                $merged[$allVarName] = $childType;
                continue;
            }

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

        return [new TemplateSignature($merged, $child->isExplicit || $parent->isExplicit), $errors];
    }

    /**
     * Walk the @extends chain starting from the given blade file.
     *
     * @return array{list<array{path: string, signature: TemplateSignature}>, list<string>}
     *         The chain (index 0 is the starting template, last index the
     *         deepest ancestor) and an error for each parent that could not be
     *         resolved, since its contract then goes unenforced.
     */
    private function resolveExtendsChain(string $bladeFilePath): array
    {
        $chain = [];
        $errors = [];
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
                // The parent's contract cannot be merged, so any variables it
                // requires go unchecked. Report it rather than degrade silently.
                $errors[] = sprintf(
                    'Template %s extends %s, which does not exist.',
                    basename($currentPath),
                    $parentViewName,
                );
                break;
            }

            $currentPath = $parentPath;
        }

        return [$chain, $errors];
    }
}
