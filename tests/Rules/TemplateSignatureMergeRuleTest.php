<?php

declare(strict_types=1);

namespace Bladestan\Tests\Rules;

use Bladestan\Compiler\SignatureMerger;
use Bladestan\Rules\TemplateSignatureMergeRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<TemplateSignatureMergeRule>
 */
final class TemplateSignatureMergeRuleTest extends RuleTestCase
{
    private const SKELETON_VIEWS = __DIR__ . '/../skeleton/resources/views';

    /**
     * Compiled shells are generated per test because their `@bladestan-source`
     * header is an absolute, machine-specific path to a real .blade.php file.
     */
    private string $compiledDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->compiledDir = sys_get_temp_dir() . '/bladestan-merge-rule-' . getmypid();
        if (! is_dir($this->compiledDir)) {
            mkdir($this->compiledDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->compiledDir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->compiledDir);

        parent::tearDown();
    }

    public function testReportsUnresolvableExtendsParentOncePerTemplate(): void
    {
        $compiledFile = $this->compileShellFor('extends-missing-layout.blade.php');

        $this->analyse([$compiledFile], [
            [
                'Template extends-missing-layout.blade.php extends layouts.does-not-exist, which does not exist.',
                1,
            ],
        ]);
    }

    public function testReportsCovarianceWideningOncePerTemplate(): void
    {
        $compiledFile = $this->compileShellFor('widen-extends.blade.php');

        $this->analyse([$compiledFile], [
            [
                'Template widen-extends.blade.php declares $siteName as string|int, but extended template '
                    . 'base-layout.blade.php declares it as string. Child templates may narrow types but not widen them.',
                1,
            ],
        ]);
    }

    public function testTemplateWithACleanSignatureProducesNothing(): void
    {
        $compiledFile = $this->compileShellFor('extends-template.blade.php');

        $this->analyse([$compiledFile], []);
    }

    public function testFilesOutsideTheCompiledPathAreIgnored(): void
    {
        // A regular source file (no @bladestan-source header, not under the
        // compiled path) must never be read for merge errors.
        $this->analyse([__DIR__ . '/Fixture/view-call-site-extends-missing-layout.php'], []);
    }

    /**
     * Write a compiled shell whose @bladestan-source header points at a real
     * skeleton template, mirroring what BladeToPHPCompiler emits.
     */
    private function compileShellFor(string $bladeFileName): string
    {
        $bladePath = realpath(self::SKELETON_VIEWS . '/' . $bladeFileName);
        assert(is_string($bladePath));

        $compiledFile = $this->compiledDir . '/' . str_replace('.blade.php', '.php', $bladeFileName);
        file_put_contents($compiledFile, "<?php\n// @bladestan-source: {$bladePath}\n");

        return $compiledFile;
    }

    /**
     * @return list<string>
     */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/config/configured_extension.neon'];
    }

    protected function getRule(): Rule
    {
        // Construct directly so the compiled path matches the per-test temp dir:
        // the container-wired %currentWorkingDirectory% resolves to the PHPStan
        // phar during tests and could never match the generated shells.
        return new TemplateSignatureMergeRule(
            self::getContainer()->getByType(SignatureMerger::class),
            $this->compiledDir,
        );
    }
}
