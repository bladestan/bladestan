<?php

declare(strict_types=1);

namespace Bladestan\Bootstrap;

use function debug_backtrace;
use function in_array;
use const DEBUG_BACKTRACE_IGNORE_ARGS;

/**
 * Recognises a parallel worker that argv cannot reveal.
 *
 * PHPStan creates a parallel worker in one of two ways. It spawns a fresh PHP
 * process running the `worker` command, which {@see PhpStanCommandResolver}
 * reads straight off argv — or it forks the main process, which inherits the
 * main process's argv verbatim. A forked worker therefore looks exactly like
 * the `analyse` run it was forked from, and the bootstrap file would boot the
 * application, print the advisories and compile the templates once per worker,
 * racing every sibling worker that is reading those same compiled files.
 *
 * The one thing that does distinguish the two is the call stack: PHPStan runs
 * the bootstrap files of a worker, spawned or forked alike, from inside its
 * worker runner. Looking for that frame answers the question for both kinds.
 *
 * @see \Bladestan\Tests\Bootstrap\ForkedWorkerDetectorTest
 */
final class ForkedWorkerDetector
{
    /**
     * The classes PHPStan runs a worker's bootstrap files from.
     *
     * @var list<string>
     */
    private const WORKER_RUNNERS = ['PHPStan\Parallel\WorkerRunner', 'PHPStan\Command\FixerWorkerRunner'];

    /**
     * Whether the calling process is a parallel worker.
     */
    public function isWorker(): bool
    {
        return $this->hasWorkerFrame(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS));
    }

    /**
     * @param list<array{class?: string}> $frames
     */
    public function hasWorkerFrame(array $frames): bool
    {
        foreach ($frames as $frame) {
            if (in_array($frame['class'] ?? '', self::WORKER_RUNNERS, true)) {
                return true;
            }
        }

        return false;
    }
}
