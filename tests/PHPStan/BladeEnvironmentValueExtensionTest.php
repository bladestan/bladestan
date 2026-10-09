<?php

declare(strict_types=1);

namespace Bladestan\Tests\PHPStan;

use Bladestan\Compiler\ViewDataResolver;
use Bladestan\Laravel\BladeEnvironmentDescriber;
use Bladestan\PHPStan\BladeEnvironmentValueExtension;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use PHPStan\Testing\PHPStanTestCase;

final class BladeEnvironmentValueExtensionTest extends PHPStanTestCase
{
    /**
     * A value that differs between two runs over the same application would
     * re-analyse every template on every run.
     */
    public function testValuesAreStable(): void
    {
        foreach ([
            BladeEnvironmentValueExtension::COMPILER,
            BladeEnvironmentValueExtension::SHARED,
            BladeEnvironmentValueExtension::FINDER,
            BladeEnvironmentValueExtension::composerKey('signed-template'),
        ] as $key) {
            $this->assertSame($this->extension()->getValue($key), $this->extension()->getValue($key), $key);
        }
    }

    public function testRegisteringAComposerChangesTheValueForItsViewOnly(): void
    {
        $viewKey = BladeEnvironmentValueExtension::composerKey('bladestan-composed-view');
        $otherKey = BladeEnvironmentValueExtension::composerKey('bladestan-other-view');
        $viewBefore = $this->extension()
            ->getValue($viewKey);
        $otherBefore = $this->extension()
            ->getValue($otherKey);

        resolve(ViewFactory::class)->composer(
            'bladestan-composed-view',
            static fn (View $view): View => $view->with('count', 1),
        );

        $this->assertNotSame($viewBefore, $this->extension()->getValue($viewKey));
        $this->assertSame($otherBefore, $this->extension()->getValue($otherKey));
    }

    public function testKeysAreStoredAsTheyAre(): void
    {
        $extension = $this->extension();
        $key = BladeEnvironmentValueExtension::composerKey('Test::namespacedView');

        $this->assertSame($key, $extension->keyToResultCache($key));
        $this->assertSame($key, $extension->keyFromResultCache($key));
    }

    /**
     * @return list<string>
     */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../Rules/config/configured_extension.neon'];
    }

    /**
     * A fresh instance per read, since one instance remembers its values for
     * the rest of the run.
     */
    private function extension(): BladeEnvironmentValueExtension
    {
        $container = self::getContainer();

        return new BladeEnvironmentValueExtension(
            $container,
            $container->getByType(ViewDataResolver::class),
            new BladeEnvironmentDescriber(),
        );
    }
}
