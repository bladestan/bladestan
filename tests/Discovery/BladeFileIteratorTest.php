<?php

declare(strict_types=1);

namespace Bladestan\Tests\Discovery;

use Bladestan\Discovery\BladeFileIterator;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class BladeFileIteratorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/bladestan-file-iterator-' . uniqid();
        mkdir($this->root . '/views/sub', 0777, true);
        mkdir($this->root . '/linked-partials', 0777, true);

        file_put_contents($this->root . '/views/a.blade.php', 'a');
        file_put_contents($this->root . '/views/sub/b.blade.php', 'b');
        file_put_contents($this->root . '/views/notes.txt', 'not a template');
        file_put_contents($this->root . '/linked-partials/c.blade.php', 'c');
    }

    protected function tearDown(): void
    {
        $this->deleteRecursively($this->root);

        parent::tearDown();
    }

    public function testFindsBladeFilesAndExcludesOthers(): void
    {
        $names = $this->viewNames($this->root . '/views');

        $this->assertContains('a.blade.php', $names);
        $this->assertContains('b.blade.php', $names);
        $this->assertNotContains('notes.txt', $names);
    }

    public function testDescendsIntoSymlinkedSubdirectories(): void
    {
        if (! @symlink($this->root . '/linked-partials', $this->root . '/views/linked')) {
            $this->markTestSkipped('The filesystem does not support symlinks.');
        }

        $names = $this->viewNames($this->root . '/views');

        // A bare RecursiveDirectoryIterator would not descend through the
        // symlink, leaving the template behind it invisible to compilation and
        // to the result-cache meta hash. Laravel's view finder does follow it.
        $this->assertContains('c.blade.php', $names);
    }

    /**
     * @return list<string>
     */
    private function viewNames(string $directory): array
    {
        $names = [];
        foreach (BladeFileIterator::over($directory) as $fileInfo) {
            $names[] = $fileInfo->getBasename();
        }

        return $names;
    }

    private function deleteRecursively(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        // Do not follow symlinks while cleaning up, or unlink would delete the
        // symlink targets' contents rather than the links themselves.
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $fileInfo */
        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isDir() && ! $fileInfo->isLink()) {
                rmdir($fileInfo->getPathname());
            } else {
                unlink($fileInfo->getPathname());
            }
        }

        rmdir($path);
    }
}
