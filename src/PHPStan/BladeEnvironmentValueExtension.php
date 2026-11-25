<?php

declare(strict_types=1);

namespace Bladestan\PHPStan;

use Bladestan\Compiler\ViewDataResolver;
use Bladestan\Laravel\ApplicationBooter;
use Bladestan\Laravel\BladeEnvironmentDescriber;
use Illuminate\Contracts\Container\Container as LaravelContainer;
use Illuminate\View\Compilers\BladeCompiler;
use PHPStan\Analyser\ResultCache\ResultCacheValueExtension;
use PHPStan\DependencyInjection\Container;
use PHPStan\Type\Type;
use PHPStan\Type\VerbosityLevel;
use Throwable;

/**
 * The parts of the Laravel application a compiled template depends on besides
 * its own source, as PHPStan's result cache sees them.
 *
 * A template's compiled form is shaped by configuration that usually lives in a
 * service provider: custom directives and other Blade compiler settings, the
 * data shared with every view, the view's composers, and the view finder that
 * decides which view name a file has. PHPStan sees none of it, so a change there
 * would leave every template's cached result stale. Each template declares the
 * keys it was compiled against, and a change re-analyses exactly the templates
 * that declared it.
 *
 * @see \Bladestan\Tests\PHPStan\BladeEnvironmentValueExtensionTest
 */
final class BladeEnvironmentValueExtension implements ResultCacheValueExtension
{
    /**
     * Blade compiler configuration: directives, conditions, precompilers,
     * extensions, echo handlers, echo tags, and component aliases, namespaces
     * and paths.
     */
    public const COMPILER = 'compiler';

    /**
     * The types of the data shared with every view.
     */
    public const SHARED = 'shared';

    /**
     * The view finder's paths, namespace hints and extensions, which decide the
     * view name a template compiles under, whether it is reachable at all, and
     * what a view name used at a call site resolves to.
     */
    public const FINDER = 'finder';

    private const COMPOSER_PREFIX = 'composer:';

    /**
     * Per-process memo: the configuration does not change during a run, and
     * every template asks for the same keys.
     *
     * @var array<string, string>
     */
    private array $values = [];

    public function __construct(
        private readonly Container $container,
        private readonly ViewDataResolver $viewDataResolver,
        private readonly BladeEnvironmentDescriber $bladeEnvironmentDescriber,
    ) {
    }

    /**
     * The key for the data a view's composers add to it.
     */
    public static function composerKey(string $viewName): string
    {
        return self::COMPOSER_PREFIX . $viewName;
    }

    public function getValue(string $key): string
    {
        return $this->values[$key] ??= $this->computeValue($key);
    }

    public function keyToResultCache(string $key): string
    {
        return $key;
    }

    public function keyFromResultCache(string $storedKey): string
    {
        return $storedKey;
    }

    private function computeValue(string $key): string
    {
        // The result cache asks for these values while it is restored, which
        // the analyse command does before it runs any bootstrap file, so the
        // application may not be running yet.
        try {
            $application = ApplicationBooter::boot();
        } catch (Throwable $throwable) {
            return 'unbootable: ' . hash('xxh128', $throwable->getMessage());
        }

        if (! $application instanceof LaravelContainer) {
            return 'no application';
        }

        try {
            $description = match (true) {
                // Taken from PHPStan's container rather than Laravel's, so it
                // has passed through the same factory as the compiler that
                // compiles the templates.
                $key === self::COMPILER => $this->bladeEnvironmentDescriber->describeCompiler(
                    $this->container->getByType(BladeCompiler::class)
                ),
                $key === self::SHARED => $this->describeTypes($this->viewDataResolver->shared()),
                $key === self::FINDER => $this->bladeEnvironmentDescriber->describeFinder(),
                str_starts_with($key, self::COMPOSER_PREFIX) => $this->describeTypes(
                    $this->viewDataResolver->composed(substr($key, strlen(self::COMPOSER_PREFIX)))
                ),
                default => 'unknown key',
            };
        } catch (Throwable $throwable) {
            // The compiler reports the same failure against the template, so
            // the failure is what the template was analysed against.
            $description = 'error: ' . $throwable->getMessage();
        }

        return hash('xxh128', $description);
    }

    /**
     * @param array<string, Type> $types
     */
    private function describeTypes(array $types): string
    {
        $described = [];
        foreach ($types as $name => $type) {
            $described[$name] = $type->describe(VerbosityLevel::precise());
        }

        ksort($described);

        return serialize($described);
    }
}
