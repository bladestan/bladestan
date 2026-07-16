<?php

declare(strict_types=1);

namespace Bladestan\Bootstrap;

use function rtrim;
use function str_starts_with;

/**
 * Finds analysed paths that point at raw `.blade.php` templates.
 *
 * Bladestan analyses templates from its compiled output under `.bladestan`, not
 * from the source `.blade.php` files. If a user adds their view directory (for
 * example `resources/views`) to PHPStan's `paths`, PHPStan parses the raw
 * templates as plain PHP and floods the report with parse errors. This detector
 * spots that misconfiguration so the bootstrap can warn about it.
 */
final class RawTemplatePathDetector
{
    /**
     * Return the analysed paths that contain at least one raw template.
     *
     * All inputs must be absolute paths normalized to forward slashes with no
     * trailing slash, so containment can be decided by a plain prefix check.
     *
     * @param list<string> $analysedPaths
     * @param list<string> $templateFilePaths
     * @return list<string>
     */
    public function conflictingPaths(array $analysedPaths, array $templateFilePaths): array
    {
        $conflicts = [];

        foreach ($analysedPaths as $analysedPath) {
            $prefix = rtrim($analysedPath, '/') . '/';

            foreach ($templateFilePaths as $templateFilePath) {
                if ($templateFilePath === $analysedPath || str_starts_with($templateFilePath, $prefix)) {
                    $conflicts[] = $analysedPath;
                    break;
                }
            }
        }

        return $conflicts;
    }
}
