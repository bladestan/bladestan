<?php

declare(strict_types=1);

namespace Bladestan\Bootstrap;

use function rtrim;
use function str_starts_with;

/**
 * Finds view directories PHPStan is not looking at.
 *
 * Templates are analysed as themselves, so a view directory has to be in
 * PHPStan's `paths` for its templates to be checked at all. Leaving it out is
 * silent: PHPStan reports no errors for the templates because it never opened
 * them. This detector spots that so the bootstrap can name the directory to
 * add.
 */
final class UnanalysedTemplateDetector
{
    /**
     * Return the view directories no analysed path reaches.
     *
     * A directory counts as reached when an analysed path contains it or lies
     * inside it: analysing `resources` covers `resources/views`, and analysing
     * `resources/views/admin` means the user scoped the run deliberately.
     * Neither is worth a warning.
     *
     * All inputs must be absolute paths normalized to forward slashes with no
     * trailing slash, so containment can be decided by a plain prefix check.
     *
     * @param list<string> $analysedPaths
     * @param list<string> $viewRoots
     * @return list<string>
     */
    public function unreachedRoots(array $analysedPaths, array $viewRoots): array
    {
        $unreached = [];

        foreach ($viewRoots as $viewRoot) {
            if ($this->isReached($viewRoot, $analysedPaths)) {
                continue;
            }

            $unreached[] = $viewRoot;
        }

        return $unreached;
    }

    /**
     * @param list<string> $analysedPaths
     */
    private function isReached(string $viewRoot, array $analysedPaths): bool
    {
        $viewRootPrefix = rtrim($viewRoot, '/') . '/';

        foreach ($analysedPaths as $analysedPath) {
            if ($analysedPath === $viewRoot) {
                return true;
            }

            if (str_starts_with($viewRoot, rtrim($analysedPath, '/') . '/')) {
                return true;
            }

            if (str_starts_with($analysedPath, $viewRootPrefix)) {
                return true;
            }
        }

        return false;
    }
}
