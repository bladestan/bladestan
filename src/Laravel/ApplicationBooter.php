<?php

declare(strict_types=1);

namespace Bladestan\Laravel;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Larastan\Larastan\ApplicationResolver;
use Orchestra\Testbench\Concerns\CreatesApplication;
use Throwable;

/**
 * Boots the project's Laravel application, once per process.
 *
 * Everything Bladestan asks Laravel — which directories hold views, how a view
 * name maps to a file, what the Blade compiler is — needs a booted
 * application. PHPStan's bootstrap phase used to be the only place that asked,
 * so bootstrap.php could boot the application inline and every later caller
 * could assume one was running. That is no longer true: the analyse command
 * defers bootstrap files until after the result cache has been restored, so
 * the result-cache meta extension runs first and finds a bare container with
 * no core bindings in it. Anything that needs an application boots it through
 * here instead, and whichever caller runs first pays for the boot.
 *
 * The boot is latched to the process that performed it: PHPStan forks parallel
 * workers from the main process and runs the bootstrap files again in each
 * one, deliberately, so that resources the application opens are per-worker
 * rather than inherited across a fork. A worker therefore boots its own
 * application instead of reusing the one it inherited.
 */
final class ApplicationBooter
{
    /**
     * Lumen is matched by name, and its boot() called dynamically, because
     * lumen/framework is not a dependency of this package: the class does not
     * exist at all in a Laravel-only installation.
     */
    private const LUMEN_APPLICATION = 'Laravel\Lumen\Application';

    private static ?int $bootedPid = null;

    private static ?Container $container = null;

    /**
     * A boot failure is remembered and re-thrown rather than retried: half of
     * a failed boot has already registered service providers, and a second
     * attempt in the same process would register them on top. Callers that can
     * work without an application (the result-cache meta extension) catch it;
     * the bootstrap file lets it surface, so a project whose application cannot
     * boot fails loudly instead of being analysed as though it had no views.
     */
    private static ?Throwable $throwable = null;

    /**
     * The booted application, or null when the project has none to boot.
     *
     * @throws Throwable when the application exists but its boot fails
     */
    public static function boot(): ?Container
    {
        // A platform without getmypid() cannot tell a forked child from its
        // parent, so treat every call there as the same process: PHPStan only
        // forks where pcntl is available, which is where getmypid() is too.
        $pid = getmypid() ?: 0;

        if (self::$bootedPid === $pid) {
            if (self::$throwable instanceof Throwable) {
                throw self::$throwable;
            }

            return self::$container;
        }

        self::$bootedPid = $pid;
        self::$container = null;
        self::$throwable = null;

        if (! defined('LARAVEL_START')) {
            define('LARAVEL_START', microtime(true));
        }

        // Snapshot the registered error and exception handlers before the
        // application is even created. Booting it installs Laravel's global
        // handlers, which have no business in an analysis process: errors
        // raised while PHPStan works would be routed into the application's
        // exception handling instead of PHPStan's own scoped handlers, and
        // PHPUnit (when this runs inside a PHPStanTestCase) flags the leaked
        // handlers on every run. The set-then-restore pair reads the current
        // handler without changing the stack.
        $previousErrorHandler = set_error_handler(static fn (): bool => false);
        restore_error_handler();
        $previousExceptionHandler = set_exception_handler(null);
        restore_exception_handler();

        try {
            self::$container = self::createAndBoot();
        } catch (Throwable $throwable) {
            self::$throwable = $throwable;

            throw $throwable;
        } finally {
            self::restoreHandlers($previousErrorHandler, $previousExceptionHandler);
        }

        return self::$container;
    }

    /**
     * @throws Throwable
     */
    private static function createAndBoot(): ?Container
    {
        $application = self::createApplication();
        if (! $application instanceof Container) {
            return null;
        }

        if ($application instanceof Application) {
            $application->make(Kernel::class)
                ->bootstrap();
        } elseif (is_a($application, self::LUMEN_APPLICATION)) {
            // Lumen has no console kernel to bootstrap; its application boots
            // itself.
            call_user_func([$application, 'boot']);
        } else {
            return null;
        }

        if (! defined('LARAVEL_VERSION')) {
            define('LARAVEL_VERSION', call_user_func([$application, 'version']));
        }

        return $application;
    }

    /**
     * @throws Throwable
     */
    private static function createApplication(): mixed
    {
        $workingDirectory = getcwd();

        // Applications and local development.
        if ($workingDirectory !== false && file_exists($applicationPath = $workingDirectory . '/bootstrap/app.php')) {
            return require $applicationPath;
        }

        // Relative path from the default vendor directory: this file sits two
        // levels below the package root, which itself sits three levels below
        // the project root at vendor/<vendor>/<package>.
        $projectRoot = dirname(__DIR__, 2 + 3);
        if (file_exists($applicationPath = $projectRoot . '/bootstrap/app.php')) {
            return require $applicationPath;
        }

        // Packages.
        if (trait_exists(CreatesApplication::class)) {
            return ApplicationResolver::resolve();
        }

        return null;
    }

    /**
     * Pop whatever the boot registered until the pre-boot handlers are back on
     * top. Bounded so a handler stack this class does not understand can never
     * loop forever.
     */
    private static function restoreHandlers(?callable $previousErrorHandler, ?callable $previousExceptionHandler): void
    {
        for ($i = 0; $i < 16; $i++) {
            $currentErrorHandler = set_error_handler(static fn (): bool => false);
            restore_error_handler();
            if ($currentErrorHandler === $previousErrorHandler) {
                break;
            }

            restore_error_handler();
        }

        for ($i = 0; $i < 16; $i++) {
            $currentExceptionHandler = set_exception_handler(null);
            restore_exception_handler();
            if ($currentExceptionHandler === $previousExceptionHandler) {
                break;
            }

            restore_exception_handler();
        }
    }
}
