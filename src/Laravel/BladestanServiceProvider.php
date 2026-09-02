<?php

declare(strict_types=1);

namespace Bladestan\Laravel;

use Bladestan\Console\GenerateBladeSignaturesCommand;
use Illuminate\Support\ServiceProvider;

/**
 * Registers Bladestan's Artisan commands in a Laravel application.
 *
 * Bladestan is installed as a dev dependency, so this provider is auto-discovered
 * only in development installs (a `composer install --no-dev` omits the package
 * entirely, and with it this provider). It contributes tooling for authoring
 * templates and never touches request handling.
 */
final class BladestanServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([GenerateBladeSignaturesCommand::class]);
        }
    }
}
