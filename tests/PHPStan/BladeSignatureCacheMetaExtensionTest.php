<?php

declare(strict_types=1);

namespace Bladestan\Tests\PHPStan;

use PHPUnit\Framework\TestCase;

final class BladeSignatureCacheMetaExtensionTest extends TestCase
{
    /**
     * PHPStan's analyse command restores its result cache, which is where this
     * extension is asked for its hash, before it executes any bootstrap file.
     * Asking a container that no application has been booted into is what used
     * to abort the whole run with "Target class [view] does not exist", so the
     * hash has to be obtainable from a bare process.
     */
    public function testProducesAHashWithoutABootstrapFileHavingRun(): void
    {
        $script = __DIR__ . '/data/print-signature-hash.php';

        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1';

        $output = [];
        $exitCode = 1;
        exec($command, $output, $exitCode);

        $printed = implode("\n", $output);

        $this->assertSame(0, $exitCode, $printed);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $printed);
    }
}
