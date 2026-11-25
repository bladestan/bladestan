<?php

declare(strict_types=1);

namespace Bladestan\NodeAnalyzer;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\View\FileViewFinder;
use Illuminate\View\ViewName;
use InvalidArgumentException;

final class TemplateFilePathResolver
{
    /**
     * @throws InvalidArgumentException
     */
    public function resolveExistingFilePath(string $resolvedValue): string
    {
        $resolvedValue = ViewName::normalize($resolvedValue);

        /** @throws InvalidArgumentException */
        $view = resolve(ViewFactory::class)
            ->getFinder()
            ->find($resolvedValue);

        return $view;
    }

    /**
     * The files the view finder tries for a view name before it settles on
     * $resolvedFilePath, in its search order, or every file it tries when the
     * view was not found ($resolvedFilePath null).
     *
     * None of them exist, or the finder would have stopped there. Creating one
     * changes what the view name resolves to: a missing view appears, or a new
     * file shadows the one found today. That makes them what a caller depends on
     * besides the resolved file itself.
     *
     * Mirrors FileViewFinder's lookup (each path, then each registered
     * extension). A finder of another kind has no lookup to mirror, so it yields
     * nothing.
     *
     * @return list<string>
     */
    public function candidateFilePathsBefore(string $viewName, ?string $resolvedFilePath): array
    {
        $finder = resolve(ViewFactory::class)->getFinder();
        if (! $finder instanceof FileViewFinder) {
            return [];
        }

        $name = trim(ViewName::normalize($viewName));
        $paths = $finder->getPaths();

        $segments = explode(FileViewFinder::HINT_PATH_DELIMITER, $name);
        if (count($segments) === 2) {
            [$namespace, $name] = $segments;

            /** @var array<string, array<string>> $hints */
            $hints = $finder->getHints();
            $paths = $hints[$namespace] ?? [];
        }

        $candidates = [];
        foreach ($paths as $path) {
            foreach ($finder->getExtensions() as $extension) {
                $candidate = $path . '/' . str_replace('.', '/', $name) . '.' . $extension;
                if ($candidate === $resolvedFilePath) {
                    return $candidates;
                }

                $candidates[] = $candidate;
            }
        }

        // The resolved file was not among the candidates, so the lookup did not
        // go the way it was mirrored here. Nothing reliable can be said about
        // which files precede it.
        return $resolvedFilePath === null ? $candidates : [];
    }
}
