<?php

declare(strict_types=1);

namespace Bladestan\Tests\PHPStan;

use App\View\Components\Panel;
use Bladestan\PHPStan\BladeEnvironmentValueExtension;
use Bladestan\PHPStan\TemplateSignatureValueExtension;
use PHPStan\Analyser\Analyser;
use PHPStan\Analyser\ResultCache\ClassResultCacheValueExtension;
use PHPStan\Analyser\ResultCache\FileResultCacheValueExtension;
use PHPStan\Testing\PHPStanTestCase;

/**
 * What each kind of file declares to PHPStan's result cache, read back from a
 * real analysis: the dependencies decide which files a later run re-analyses,
 * so a missing one is a stale result and a surplus one is wasted work.
 */
final class DependencyTrackingTest extends PHPStanTestCase
{
    private const VIEWS = __DIR__ . '/../skeleton/resources/views';

    public function testCallSiteDependsOnTheContractOfEveryTemplateInTheChain(): void
    {
        $dependencies = $this->dependenciesOf(__DIR__ . '/../Rules/Fixture/view-call-site-extends-missing-parent-param.php');

        $this->assertContains([TemplateSignatureValueExtension::class, $this->view('extends-template.blade.php')], $dependencies);
        $this->assertContains([TemplateSignatureValueExtension::class, $this->view('layouts/base-layout.blade.php')], $dependencies);
        $this->assertContains([BladeEnvironmentValueExtension::class, BladeEnvironmentValueExtension::FINDER], $dependencies);

        // A body edit to the template must not re-analyse its callers, so the
        // caller must not depend on the template file as a whole.
        $this->assertNotContains([FileResultCacheValueExtension::class, $this->view('extends-template.blade.php')], $dependencies);
    }

    public function testCallSiteOfAMissingTemplateDependsOnTheFilesTheFinderLooksFor(): void
    {
        $dependencies = $this->dependenciesOf(__DIR__ . '/../Rules/Fixture/view-call-site-missing-template.php');

        $this->assertContains([FileResultCacheValueExtension::class, $this->view('does-not-exist-yet.blade.php')], $dependencies);
        $this->assertContains([FileResultCacheValueExtension::class, $this->view('does-not-exist-yet.php')], $dependencies);
    }

    public function testTemplateDependsOnTheContractOfItsAncestors(): void
    {
        $dependencies = $this->dependenciesOf($this->view('extends-template.blade.php'));

        $this->assertContains([TemplateSignatureValueExtension::class, $this->view('layouts/base-layout.blade.php')], $dependencies);
    }

    public function testTemplateExtendingAMissingLayoutDependsOnTheFilesTheFinderLooksFor(): void
    {
        $dependencies = $this->dependenciesOf($this->view('extends-missing-layout.blade.php'));

        $this->assertContains([FileResultCacheValueExtension::class, $this->view('layouts/does-not-exist.blade.php')], $dependencies);
    }

    public function testTemplateDependsOnTheApplicationConfigurationItWasCompiledAgainst(): void
    {
        $dependencies = $this->dependenciesOf($this->view('signed-template.blade.php'));

        $this->assertContains([BladeEnvironmentValueExtension::class, BladeEnvironmentValueExtension::COMPILER], $dependencies);
        $this->assertContains([BladeEnvironmentValueExtension::class, BladeEnvironmentValueExtension::SHARED], $dependencies);
        $this->assertContains([BladeEnvironmentValueExtension::class, BladeEnvironmentValueExtension::FINDER], $dependencies);
        $this->assertContains(
            [BladeEnvironmentValueExtension::class, BladeEnvironmentValueExtension::composerKey('signed-template')],
            $dependencies,
        );
    }

    public function testComponentTemplateDependsOnItsBackingClass(): void
    {
        $dependencies = $this->dependenciesOf($this->view('components/panel.blade.php'));

        $this->assertContains([ClassResultCacheValueExtension::class, Panel::class], $dependencies);
    }

    public function testComponentTemplateWithoutABackingClassDependsOnTheClassItWouldHave(): void
    {
        // Creating the class changes the component's scope as much as editing it.
        $dependencies = $this->dependenciesOf($this->view('components/alert.blade.php'));

        $this->assertContains([ClassResultCacheValueExtension::class, 'App\View\Components\Alert'], $dependencies);
    }

    /**
     * @return list<string>
     */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../Rules/config/configured_extension.neon'];
    }

    /**
     * Every value the analysis of $file declared, as [extension class, key].
     *
     * @return list<array{string, string}>
     */
    private function dependenciesOf(string $file): array
    {
        $file = $this->getFileHelper()
            ->normalizePath($file);
        $valueDependencies = self::getContainer()->getByType(Analyser::class)
            ->analyse([$file])
            ->getValueDependencies();
        $this->assertNotNull($valueDependencies);

        $dependencies = [];
        foreach ($valueDependencies['dependents'][$file]['analysis'] ?? [] as $id) {
            [$extensionClass, $key] = $valueDependencies['values'][$id];
            $dependencies[] = [$extensionClass, $key];
        }

        return $dependencies;
    }

    private function view(string $relativePath): string
    {
        return (realpath(self::VIEWS) ?: self::VIEWS) . '/' . $relativePath;
    }
}
