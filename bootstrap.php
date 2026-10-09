<?php

declare(strict_types=1);

use Bladestan\Bootstrap\ForkedWorkerDetector;
use Bladestan\Bootstrap\PhpStanCommandResolver;
use Bladestan\Bootstrap\UnanalysedTemplateDetector;
use Bladestan\Discovery\TemplateDiscovery;
use Bladestan\Laravel\ApplicationBooter;

// Boots the project's Laravel application so everything Bladestan asks Laravel
// during analysis (which directories hold views, how a view name maps to a
// file, what the Blade compiler is) has one to ask, and warns when PHPStan is
// not looking at the templates at all.
//
// Templates themselves are compiled by BladeTemplateParser, at the moment
// PHPStan asks for a template's statements. Nothing is compiled here: PHPStan
// finishes discovering the files it will analyse before it runs a bootstrap
// file, so anything written to disk from here would not be picked up until the
// following run.

// PHPStan runs bootstrap files in every process. Derive the command and worker
// flags from argv, which is available before the application boots: the
// advisory below is for the person who invoked PHPStan, and should be printed
// once by the main process under the analyse command, not once per worker or
// once per unrelated command.
/** @var list<string> $bladestanArgv */
$bladestanArgv = array_values(array_map(
    static fn (mixed $bladestanToken): string => is_scalar($bladestanToken) ? (string) $bladestanToken : '',
    (array) ($_SERVER['argv'] ?? []),
));
// An absent command means `analyse`, because PHPStan's binary sets it as the
// default one — but only for that binary. A process that merely builds a
// PHPStan container and runs its bootstrap files (a PHPStanTestCase under
// PHPUnit, an editor integration embedding the analyser) has argv of its own,
// which would otherwise read as an unnamed analyse run and print advice meant
// for someone running PHPStan. The binary defines __PHPSTAN_RUNNING__ before
// anything else happens, so that constant tells the two apart; its own name is
// the fallback, so a release that stops defining the constant degrades to a
// slightly cruder check.
$bladestanCommandResolver = new PhpStanCommandResolver();
$bladestanIsPhpStanCli = defined('__PHPSTAN_RUNNING__')
    || str_starts_with(basename($bladestanArgv[0] ?? ''), 'phpstan');
// A worker PHPStan forked from the main process inherits that process's argv,
// so it reads as the analyse run it was forked from; only its call stack gives
// it away. Both kinds of worker have to be recognised here or the advisory
// would be printed once per core.
$bladestanIsWorkerProcess = $bladestanCommandResolver->isWorker($bladestanArgv)
    || (new ForkedWorkerDetector())->isWorker();
$bladestanIsAnalyseCommand = $bladestanIsPhpStanCli
    && ! $bladestanIsWorkerProcess
    && $bladestanCommandResolver->isAnalyse($bladestanArgv);

$bladestanReportUnanalysed = true;
/** @var list<string> $bladestanAnalysedPaths */
$bladestanAnalysedPaths = [];
/** @var list<string> $bladestanConfiguredPaths */
$bladestanConfiguredPaths = [];
$bladestanExtensionLoaded = false;
// Whether PHPStan's path parameters could be read at all. When they cannot, the
// loud warning below is the whole story; the advisory has no basis to speak.
$bladestanPathsReadable = false;

// PHPStan makes its DI $container available inside bootstrap files — read the
// settings from it so config/extension.neon stays the single source of truth.
if (isset($container) && $container instanceof PHPStan\DependencyInjection\Container) {
    try {
        /** @var array{reportUnanalysedTemplates?: bool} $bladestanParameters */
        $bladestanParameters = $container->getParameter('bladestan');
        $bladestanExtensionLoaded = true;
        $bladestanReportUnanalysed = $bladestanParameters['reportUnanalysedTemplates'] ?? true;
    } catch (Throwable) {
        // The `bladestan` parameter is absent, so the extension's config was
        // never loaded (e.g. a minimal test config that pulls in this bootstrap
        // without extension.neon). There is nothing to advise about, so stay
        // quiet.
    }

    // `analysedPaths` is an internal PHPStan container parameter
    // (parametersSchema.neon marks it "internal parameters only for
    // DerivativeContainerFactory") with no supported public alternative today.
    // We read it to decide whether the view directories are among the analysed
    // paths. If a PHPStan upgrade renames or removes it, this read throws — and
    // because the advisory is the only thing that would notice a view directory
    // missing from `paths`, PHPStan would report a clean "no errors found" that
    // hides every template. Fail loudly instead, but only once the extension is
    // actually loaded (so minimal test configs stay silent).
    if ($bladestanExtensionLoaded) {
        try {
            /** @var list<string> $bladestanAnalysedPaths */
            $bladestanAnalysedPaths = $container->getParameter('analysedPaths');
            $bladestanPathsReadable = true;

            // `analysedPathsFromConfig` holds the `paths` from the config file,
            // which is what the advisory is really about. It equals
            // `analysedPaths` on an ordinary run; the two differ only when paths
            // were passed on the command line, which replaces the analysed set
            // for that run. Reading it separately keeps a rename of this one
            // parameter from raising the (then untrue) warning above; falling
            // back just makes a scoped run indistinguishable from a configured
            // one, as it was before the parameter was consulted at all.
            try {
                /** @var list<string> $bladestanConfiguredPaths */
                $bladestanConfiguredPaths = $container->getParameter('analysedPathsFromConfig');
            } catch (Throwable) {
                $bladestanConfiguredPaths = $bladestanAnalysedPaths;
            }
        } catch (Throwable) {
            if ($bladestanIsAnalyseCommand) {
                fwrite(
                    STDERR,
                    'Warning: Bladestan could not read PHPStan\'s internal "analysedPaths" parameter, which it relies on to check that your Blade templates are among the analysed paths. '
                    . 'This usually means a PHPStan upgrade changed or removed it. If your view directory is missing from "paths", your templates will NOT be analysed and nothing will say so (view() call sites are still checked). '
                    . "Please report this at https://github.com/luxplus/bladestan/issues so the extension can be updated.\n",
                );
            }
        }
    }
}

$app = ApplicationBooter::boot();

// Advisory: a view directory is missing from PHPStan's `paths`, so its
// templates are never opened and their errors are silently absent. A run scoped
// to paths on the command line (`phpstan analyse app`) analyses only those, so
// nothing is misconfigured there and the advisory is skipped: the configured
// paths, not this run's, decide whether there is anything to say.
if ($app !== null && $bladestanIsAnalyseCommand && $bladestanPathsReadable && $bladestanReportUnanalysed) {
    $bladestanNormalize = static fn (string $path): string => rtrim(
        str_replace('\\', '/', realpath($path) ?: $path),
        '/',
    );

    try {
        $bladestanViewRoots = array_values(
            array_map($bladestanNormalize, (new TemplateDiscovery())->getViewRoots())
        );

        $bladestanUnreached = (new UnanalysedTemplateDetector())->unreachedRoots(
            array_values(array_map($bladestanNormalize, $bladestanConfiguredPaths)),
            $bladestanViewRoots,
        );

        foreach ($bladestanUnreached as $bladestanUnreachedRoot) {
            fwrite(STDERR, sprintf(
                'Warning: Bladestan is not analysing the Blade templates in "%s" because it is missing from PHPStan\'s "paths" (view() call sites are still checked). '
                . "Add it to analyse your templates, or set parameters.bladestan.reportUnanalysedTemplates: false to silence.\n",
                $bladestanUnreachedRoot,
            ));
        }
    } catch (Throwable) {
        // View discovery failed; the advisory has nothing to say.
    }
}
