<?php

declare(strict_types=1);

namespace Bladestan\Tests\PHPStan;

use Bladestan\PHPStan\BladeSignatureCacheMetaExtension;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class BladeSignatureCacheMetaExtensionTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $createdPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->createdPaths as $path) {
            $this->removeDirectory($path);
        }

        $this->createdPaths = [];
    }

    #[Test]
    public function hashIsStableWithoutBootedLaravelApplication(): void
    {
        // PHPStan calls getHash() before the bootstrapFiles run, so no Laravel
        // container is available here. The hash must not depend on one.
        $templateDirectory = $this->createTemplateDirectory(['layout.blade.php' => '<div>body</div>']);

        $extension = new BladeSignatureCacheMetaExtension([$templateDirectory]);

        $this->assertSame($extension->getHash(), $extension->getHash());
    }

    #[Test]
    public function hashChangesWhenTemplateContentChanges(): void
    {
        $templateDirectory = $this->createTemplateDirectory(['layout.blade.php' => '<div>body</div>']);

        $extension = new BladeSignatureCacheMetaExtension([$templateDirectory]);

        $before = $extension->getHash();

        file_put_contents($templateDirectory . '/layout.blade.php', '<div>changed</div>');

        $this->assertNotSame($before, $extension->getHash());
    }

    #[Test]
    public function hashChangesWhenTemplateIsAdded(): void
    {
        $templateDirectory = $this->createTemplateDirectory(['layout.blade.php' => '<div>body</div>']);

        $extension = new BladeSignatureCacheMetaExtension([$templateDirectory]);

        $before = $extension->getHash();

        file_put_contents($templateDirectory . '/page.blade.php', '<p>page</p>');

        $this->assertNotSame($before, $extension->getHash());
    }

    #[Test]
    public function hashIgnoresFilesThatAreNotBladeTemplates(): void
    {
        $templateDirectory = $this->createTemplateDirectory(
            ['layout.blade.php' => '<div>body</div>', 'notes.txt' => 'hello']
        );

        $extension = new BladeSignatureCacheMetaExtension([$templateDirectory]);

        $before = $extension->getHash();

        file_put_contents($templateDirectory . '/notes.txt', 'changed');

        $this->assertSame($before, $extension->getHash());
    }

    #[Test]
    public function missingTemplatePathDoesNotBreakHashing(): void
    {
        $extension = new BladeSignatureCacheMetaExtension(['/path/that/does/not/exist']);

        $this->assertSame($extension->getHash(), $extension->getHash());
    }

    #[Test]
    public function hashDoesNotDependOnDirectoryIterationOrder(): void
    {
        $templateDirectory = $this->createTemplateDirectory(
            ['a.blade.php' => 'a', 'b.blade.php' => 'b', 'c.blade.php' => 'c']
        );

        $first = new BladeSignatureCacheMetaExtension([$templateDirectory]);
        $second = new BladeSignatureCacheMetaExtension([$templateDirectory]);

        $this->assertSame($first->getHash(), $second->getHash());
    }

    /**
     * @param array<string, string> $files
     */
    private function createTemplateDirectory(array $files): string
    {
        $templateDirectory = sys_get_temp_dir() . '/bladestan-meta-test-' . uniqid('', true);
        if (! mkdir($templateDirectory, 0777, true) && ! is_dir($templateDirectory)) {
            $this->fail(sprintf('Could not create "%s"', $templateDirectory));
        }

        $this->createdPaths[] = $templateDirectory;

        foreach ($files as $name => $contents) {
            file_put_contents($templateDirectory . '/' . $name, $contents);
        }

        return $templateDirectory;
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($directory);
    }
}
