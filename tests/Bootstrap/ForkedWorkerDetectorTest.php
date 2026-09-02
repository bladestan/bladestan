<?php

declare(strict_types=1);

namespace Bladestan\Tests\Bootstrap;

use Bladestan\Bootstrap\ForkedWorkerDetector;
use PHPUnit\Framework\TestCase;

final class ForkedWorkerDetectorTest extends TestCase
{
    public function testMainProcessIsNotAWorker(): void
    {
        $frames = [
            [
                'class' => 'PHPStan\Command\BootstrapFilesRunner',
                'function' => 'run',
            ],
            [
                'class' => 'PHPStan\Command\AnalyserRunner',
                'function' => 'runAnalyser',
            ],
            [
                'class' => 'PHPStan\Command\AnalyseApplication',
                'function' => 'analyse',
            ],
        ];

        $this->assertFalse((new ForkedWorkerDetector())->hasWorkerFrame($frames));
    }

    public function testAWorkerRunnerOnTheStackMarksAWorker(): void
    {
        $frames = [
            [
                'class' => 'PHPStan\Command\BootstrapFilesRunner',
                'function' => 'run',
            ],
            [
                'class' => 'PHPStan\Parallel\WorkerRunner',
                'function' => 'run',
            ],
            [
                'class' => 'PHPStan\Parallel\ForkedProcess',
                'function' => 'start',
            ],
        ];

        $this->assertTrue((new ForkedWorkerDetector())->hasWorkerFrame($frames));
    }

    public function testTheFixerWorkerRunnerAlsoMarksAWorker(): void
    {
        $frames = [[
            'class' => 'PHPStan\Command\FixerWorkerRunner',
            'function' => 'run',
        ]];

        $this->assertTrue((new ForkedWorkerDetector())->hasWorkerFrame($frames));
    }

    public function testFramesWithoutAClassAreIgnored(): void
    {
        $frames = [[
            'function' => 'require',
        ], [
            'function' => 'include_once',
        ]];

        $this->assertFalse((new ForkedWorkerDetector())->hasWorkerFrame($frames));
    }

    public function testThisTestRunIsNotAWorker(): void
    {
        $this->assertFalse((new ForkedWorkerDetector())->isWorker());
    }
}
