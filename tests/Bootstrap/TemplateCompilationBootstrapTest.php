<?php

declare(strict_types=1);

namespace Bladestan\Tests\Bootstrap;

use App\Livewire\WiredComponent;
use App\View\Components\BackedComponent;
use Bladestan\Bootstrap\TemplateCompilationBootstrap;
use Bladestan\Compiler\BladeToPHPCompiler;
use Bladestan\Compiler\ComponentClassShapeHasher;
use Bladestan\Discovery\TemplateDiscovery;
use FilesystemIterator;
use PHPStan\Testing\PHPStanTestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * @phpstan-type ManifestEntry array{source: string, sourceHash: string, dependencyHash: string, classHashes: array<string, string>, output: string}
 * @phpstan-type Manifest array{projectRoot: string, contextHash: string, templates: array<string, ManifestEntry>}
 */
final class TemplateCompilationBootstrapTest extends PHPStanTestCase
{
    private const OUTPUT_ROOT = '__templates__';

    private const MANIFEST = 'bladestan-manifest.json';

    private string $compiledViewPath;

    private string $projectRoot;

    protected function setUp(): void
    {
        parent::setUp();

        // The repo root: real vendor/ templates fall under projectRoot/vendor/
        // and are skipped, leaving only the skeleton templates to compile.
        $this->projectRoot = (string) realpath(__DIR__ . '/../..');
        $this->compiledViewPath = sys_get_temp_dir() . '/bladestan-bootstrap-test-' . uniqid();
    }

    protected function tearDown(): void
    {
        $this->deleteRecursively($this->compiledViewPath);

        parent::tearDown();
    }

    public function testCompilesDiscoveredTemplatesAndWritesManifest(): void
    {
        $this->createBootstrap()
            ->run();

        $compiled = $this->outputPath('signed-template');
        $this->assertFileExists($compiled);
        $this->assertStringContainsString('@bladestan-source', (string) file_get_contents($compiled));
        $this->assertFileExists($this->compiledViewPath . '/' . self::MANIFEST);
    }

    public function testSkipsUnchangedTemplatesOnRerun(): void
    {
        $templateCompilationBootstrap = $this->createBootstrap();
        $templateCompilationBootstrap->run();

        $compiled = $this->outputPath('signed-template');
        // Backdate the compiled file; an incremental rerun must not rewrite it.
        $past = time() - 1000;
        touch($compiled, $past);

        $templateCompilationBootstrap->run();

        clearstatcache();
        $this->assertSame($past, filemtime($compiled), 'Unchanged template was recompiled');
    }

    public function testRecompilesWhenDependencyHashChangesEvenIfSourceIsUnchanged(): void
    {
        // components.panel is backed by App\View\Components\Panel (reflected
        // into the compiled output); its own .blade.php source never mentions
        // the class's properties, so a shape change there leaves sourceHash
        // untouched. Simulate that by corrupting the stored dependencyHash,
        // standing in for what an outdated manifest looks like after the
        // backing class or a view composer changed shape.
        $templateCompilationBootstrap = $this->createBootstrap();
        $templateCompilationBootstrap->run();

        $compiled = $this->outputPath('components.panel');
        $past = time() - 1000;
        touch($compiled, $past);

        $this->writeManifestEntry('components.panel', [
            ...$this->manifestEntry('components.panel'),
            'dependencyHash' => 'stale-dependency-hash',
        ]);

        $templateCompilationBootstrap->run();

        clearstatcache();
        $this->assertNotSame($past, filemtime($compiled), 'Template with a stale dependencyHash was not recompiled');
    }

    public function testRecordsTheClassOfEveryComponentATemplateRenders(): void
    {
        $this->createBootstrap()
            ->run();

        $this->assertSame(
            [BackedComponent::class],
            array_keys($this->manifestEntry('backed-component-with-brackets')['classHashes']),
        );
        $this->assertSame(
            [WiredComponent::class],
            array_keys($this->manifestEntry('livewire-with-kebab-attributes')['classHashes']),
        );
    }

    public function testRecompilesWhenARenderedComponentClassChangesShape(): void
    {
        // The compiled output of backed-component-with-brackets calls
        // App\View\Components\BackedComponent's constructor, so that
        // constructor is a compilation input the template's own source hash
        // cannot see: adding a required parameter to the component leaves every
        // compiled caller passing the old argument list. Simulate the changed
        // constructor by corrupting the recorded shape hash, which is what an
        // outdated manifest looks like after the component was edited.
        $templateCompilationBootstrap = $this->createBootstrap();
        $templateCompilationBootstrap->run();

        $compiled = $this->outputPath('backed-component-with-brackets');
        $past = time() - 1000;
        touch($compiled, $past);

        $this->writeManifestEntry('backed-component-with-brackets', [
            ...$this->manifestEntry('backed-component-with-brackets'),
            'classHashes' => [
                BackedComponent::class => 'stale-class-hash',
            ],
        ]);

        $templateCompilationBootstrap->run();

        clearstatcache();
        $this->assertNotSame(
            $past,
            filemtime($compiled),
            'Template rendering a changed component class was not recompiled',
        );
    }

    public function testRecompilesEverythingWhenTheManifestPredatesAStalenessCheck(): void
    {
        // An entry written before the manifest grew a field cannot be trusted:
        // the missing field is exactly the check that would have caught a stale
        // template. Dropping it must force a recompile, not read as "no
        // component classes to verify".
        $templateCompilationBootstrap = $this->createBootstrap();
        $templateCompilationBootstrap->run();

        $compiled = $this->outputPath('backed-component-with-brackets');
        $past = time() - 1000;
        touch($compiled, $past);

        $this->dropManifestEntryField('backed-component-with-brackets', 'classHashes');

        $templateCompilationBootstrap->run();

        clearstatcache();
        $this->assertNotSame($past, filemtime($compiled), 'An incomplete manifest entry was trusted');
    }

    public function testKeepsCompiledOutputWhenSourceIsMomentarilyUnreadable(): void
    {
        $templateCompilationBootstrap = $this->createBootstrap();
        $templateCompilationBootstrap->run();

        $compiled = $this->outputPath('foo');
        $this->assertFileExists($compiled);

        $source = $this->projectRoot . '/tests/skeleton/resources/views/foo.blade.php';
        $originalMode = fileperms($source) & 0777;

        try {
            chmod($source, 0000);
            clearstatcache();
            // On a permissive environment (e.g. running as root) the file stays
            // readable, so the branch under test never triggers; skip rather
            // than assert a condition we can't create.
            if (@file_get_contents($source) !== false) {
                $this->markTestSkipped('Cannot make the source unreadable in this environment.');
            }

            $past = time() - 1000;
            touch($compiled, $past);

            $templateCompilationBootstrap->run();

            clearstatcache();
            // The template still exists, so its compiled output must survive
            // the prune pass untouched instead of being deleted as an orphan.
            $this->assertFileExists($compiled);
            $this->assertSame($past, filemtime($compiled), 'A readable-but-unchanged template was recompiled or dropped');
        } finally {
            chmod($source, $originalMode);
        }
    }

    public function testPrunesOrphanedOutput(): void
    {
        $templateCompilationBootstrap = $this->createBootstrap();
        $templateCompilationBootstrap->run();

        // Inject an output file plus a manifest entry for a view that discovery
        // no longer returns; a rerun must prune it.
        $orphanRelative = self::OUTPUT_ROOT . '/orphaned-view.php';
        $orphanPath = $this->compiledViewPath . '/' . $orphanRelative;
        file_put_contents($orphanPath, '<?php // orphan');

        $this->writeManifestEntry('orphaned-view', [
            'source' => '/gone/orphaned-view.blade.php',
            'sourceHash' => 'deadbeef',
            'dependencyHash' => 'deadbeef',
            'classHashes' => [],
            'output' => $orphanRelative,
        ]);

        $templateCompilationBootstrap->run();

        $this->assertFileDoesNotExist($orphanPath);
    }

    public function testWipeKeepsSiblingUserFiles(): void
    {
        // Simulate a compiledViewPath misconfigured onto a directory with user
        // content. With no manifest, run() triggers a full wipe — which must
        // touch only the generated tree, never sibling files.
        mkdir($this->compiledViewPath . '/' . self::OUTPUT_ROOT, 0777, true);
        $userFile = $this->compiledViewPath . '/important-user-data.txt';
        file_put_contents($userFile, 'do not delete me');
        $staleOutput = $this->compiledViewPath . '/' . self::OUTPUT_ROOT . '/stale.php';
        file_put_contents($staleOutput, '<?php // stale generated output');

        $this->createBootstrap()
            ->run();

        $this->assertFileExists($userFile);
        $this->assertSame('do not delete me', file_get_contents($userFile));
        $this->assertFileDoesNotExist($staleOutput);
    }

    /**
     * @return list<string>
     */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../../config/extension.neon'];
    }

    private function createBootstrap(): TemplateCompilationBootstrap
    {
        return new TemplateCompilationBootstrap(
            self::getContainer()->getByType(TemplateDiscovery::class),
            self::getContainer()->getByType(BladeToPHPCompiler::class),
            new ComponentClassShapeHasher(),
            $this->compiledViewPath,
            $this->projectRoot,
        );
    }

    private function outputPath(string $viewName): string
    {
        return $this->compiledViewPath . '/' . self::OUTPUT_ROOT . '/' . str_replace('.', '/', $viewName) . '.php';
    }

    /**
     * @return ManifestEntry
     */
    private function manifestEntry(string $viewName): array
    {
        $manifest = $this->readManifest();
        $this->assertArrayHasKey($viewName, $manifest['templates']);

        return $manifest['templates'][$viewName];
    }

    /**
     * @return Manifest
     */
    private function readManifest(): array
    {
        /** @var Manifest $manifest */
        $manifest = json_decode((string) file_get_contents($this->compiledViewPath . '/' . self::MANIFEST), true);

        return $manifest;
    }

    /**
     * Replace one template's manifest entry, standing in for the state an
     * outdated manifest is in when a compilation input changed behind
     * Bladestan's back.
     *
     * @param ManifestEntry $entry
     */
    private function writeManifestEntry(string $viewName, array $entry): void
    {
        $manifest = $this->readManifest();
        $manifest['templates'][$viewName] = $entry;

        file_put_contents($this->compiledViewPath . '/' . self::MANIFEST, (string) json_encode($manifest));
    }

    /**
     * Drop a field from one entry, standing in for a manifest written before the
     * format grew that field.
     */
    private function dropManifestEntryField(string $viewName, string $field): void
    {
        $manifestPath = $this->compiledViewPath . '/' . self::MANIFEST;
        /** @var array{templates: array<string, array<string, mixed>>} $manifest */
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        unset($manifest['templates'][$viewName][$field]);

        file_put_contents($manifestPath, (string) json_encode($manifest));
    }

    private function deleteRecursively(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $fileInfo */
        foreach ($iterator as $fileInfo) {
            $fileInfo->isDir() ? rmdir($fileInfo->getPathname()) : unlink($fileInfo->getPathname());
        }

        rmdir($path);
    }
}
