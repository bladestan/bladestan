<?php

declare(strict_types=1);

use Bladestan\Blade\PhpLineToTemplateLineResolver;
use Bladestan\Bootstrap\RawTemplatePathDetector;
use Bladestan\Bootstrap\TemplateCompilationBootstrap;
use Bladestan\Compiler\BladeToPHPCompiler;
use Bladestan\Compiler\ComponentScopeResolver;
use Bladestan\Compiler\FileNameAndLineNumberAddingPreCompiler;
use Bladestan\Compiler\LivewireTagCompiler;
use Bladestan\Compiler\SignatureExtractor;
use Bladestan\Discovery\TemplateDiscovery;
use Bladestan\Laravel\View\BladeCompilerFactory;
use Bladestan\NodeAnalyzer\ValueResolver;
use Bladestan\PhpParser\ArrayStringToArrayConverter;
use Bladestan\PhpParser\NodeVisitor\BladeLineNumberNodeVisitor;
use Bladestan\PhpParser\SimplePhpParser;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Foundation\Application;
use Larastan\Larastan\ApplicationResolver;
use Laravel\Lumen\Application as LumenApplication;
use Orchestra\Testbench\Concerns\CreatesApplication;
use PhpParser\ConstExprEvaluator;
use PhpParser\PrettyPrinter\Standard;

if (! defined('LARAVEL_START')) {
    define('LARAVEL_START', microtime(true));
}

// PHPStan makes its DI $container available inside bootstrap files — read the
// compiled path from it so config/extension.neon stays the single source of
// truth. Template compilation is opt-in: it only runs when the compiled
// directory is among the analysed paths (the user added `.bladestan` to their
// `paths`). Without it, call-site signature validation still works and no
// compilation time is spent.
$bladestanCompiledViewPath = getcwd() . '/.bladestan';
$bladestanShouldCompile = false;
$bladestanExtensionLoaded = false;
$bladestanReportUnanalysed = true;
$bladestanErrorFormatConfigured = false;
/** @var list<string> $bladestanAnalysedPaths */
$bladestanAnalysedPaths = [];
if (isset($container) && $container instanceof PHPStan\DependencyInjection\Container) {
    try {
        /** @var array{compiledViewPath: string, reportUnanalysedTemplates?: bool} $bladestanParameters */
        $bladestanParameters = $container->getParameter('bladestan');
        $bladestanCompiledViewPath = $bladestanParameters['compiledViewPath'];
        $bladestanExtensionLoaded = true;
        $bladestanReportUnanalysed = $bladestanParameters['reportUnanalysedTemplates'] ?? true;

        // A format set in the config counts the same as one passed on the CLI:
        // either way the user chose their output, so the blade-remap advisory
        // below stays quiet.
        $bladestanErrorFormatConfigured = $container->getParameter('errorFormat') !== null;

        /** @var list<string> $bladestanAnalysedPaths */
        $bladestanAnalysedPaths = $container->getParameter('analysedPaths');
        $bladestanNormalizedTarget = rtrim(str_replace('\\', '/', $bladestanCompiledViewPath), '/');
        foreach ($bladestanAnalysedPaths as $bladestanAnalysedPath) {
            if (rtrim(str_replace('\\', '/', $bladestanAnalysedPath), '/') === $bladestanNormalizedTarget) {
                $bladestanShouldCompile = true;
                break;
            }
        }
    } catch (Throwable) {
        // Parameter not defined (e.g. minimal test config) — skip compilation.
    }
}

// The compiled directory must exist before PHPStan's file discovery runs.
if ($bladestanShouldCompile && ! is_dir($bladestanCompiledViewPath)) {
    @mkdir($bladestanCompiledViewPath, 0777, true);
}

// Snapshot the registered error and exception handlers before the app is even
// created. Booting the kernel (directly below, or inside Testbench's resolver
// for packages) installs Laravel's global handlers, which have no business in
// an analysis process: errors raised while PHPStan works would be routed into
// the app's exception handling instead of PHPStan's own scoped handlers, and
// PHPUnit (when this file loads inside a PHPStanTestCase) flags the leaked
// handlers on every run. The set-then-restore pair reads the current handler
// without changing the stack.
$bladestanPreviousErrorHandler = set_error_handler(static fn (): bool => false);
restore_error_handler();
$bladestanPreviousExceptionHandler = set_exception_handler(null);
restore_exception_handler();

if (file_exists($applicationPath = getcwd() . '/bootstrap/app.php')) { // Applications and Local Dev
    $app = require $applicationPath;
} elseif (file_exists(
    $applicationPath = dirname(__DIR__, 3) . '/bootstrap/app.php'
)) { // Relative path from default vendor dir
    $app = require $applicationPath;
} elseif (trait_exists(CreatesApplication::class)) { // Packages
    $app = ApplicationResolver::resolve();
}

if (isset($app)) {
    if ($app instanceof Application) {
        $app->make(Kernel::class)->bootstrap();
    } elseif ($app instanceof LumenApplication) {
        $app->boot();
    }

    // Pop whatever the boot registered until the pre-boot handlers are back on
    // top. Bounded so a handler stack this file does not understand can never
    // loop forever.
    for ($bladestanI = 0; $bladestanI < 16; $bladestanI++) {
        $bladestanCurrentErrorHandler = set_error_handler(static fn (): bool => false);
        restore_error_handler();
        if ($bladestanCurrentErrorHandler === $bladestanPreviousErrorHandler) {
            break;
        }

        restore_error_handler();
    }

    for ($bladestanI = 0; $bladestanI < 16; $bladestanI++) {
        $bladestanCurrentExceptionHandler = set_exception_handler(null);
        restore_exception_handler();
        if ($bladestanCurrentExceptionHandler === $bladestanPreviousExceptionHandler) {
            break;
        }

        restore_exception_handler();
    }

    if (! defined('LARAVEL_VERSION')) {
        define('LARAVEL_VERSION', $app->version());
    }

    // PHPStan executes bootstrap files in EVERY process — including each
    // parallel worker (WorkerCommand and FixerWorkerCommand both go through
    // CommandHelper::begin()). Only the main process may compile: it finishes
    // before workers spawn, and recompiling from a worker would race against
    // sibling workers analyzing the compiled files.
    $bladestanArgv = $_SERVER['argv'] ?? [];
    $bladestanIsWorkerProcess = in_array($bladestanArgv[1] ?? '', ['worker', 'fixer:worker'], true);

    if (! $bladestanIsWorkerProcess) {
        $bladestanTemplateDiscovery = new TemplateDiscovery();

        // The advisories below only make sense for a real analysis run. Other
        // commands (clear-result-cache, diagnose) and other hosts of the PHPStan
        // container (PHPStan's own test cases) load this bootstrap too, and a
        // configuration warning there is pure noise.
        $bladestanIsAnalyseCommand = in_array($bladestanArgv[1] ?? '', ['analyse', 'analyze'], true);

        // Advisory: raw `.blade.php` files must never be analysed directly.
        // Bladestan analyses templates from its compiled output under
        // `.bladestan`; a view directory left in PHPStan's `paths` makes PHPStan
        // parse the raw templates as plain PHP and report meaningless errors.
        // Warn rather than fail so an intentional setup still runs.
        $bladestanNormalize = static fn (string $path): string => rtrim(
            str_replace('\\', '/', realpath($path) ?: $path),
            '/',
        );

        $bladestanConflicts = [];
        try {
            $bladestanConflicts = (new RawTemplatePathDetector())->conflictingPaths(
                array_values(array_map($bladestanNormalize, $bladestanAnalysedPaths)),
                array_values(array_map($bladestanNormalize, $bladestanTemplateDiscovery->getFilePaths())),
            );

            if ($bladestanIsAnalyseCommand) {
                foreach ($bladestanConflicts as $bladestanConflict) {
                    fwrite(STDERR, sprintf(
                        'Warning: Bladestan found raw .blade.php files under analysed path "%s"; PHPStan reads them as plain PHP. '
                        . "Remove it from \"paths\" and add \".bladestan\" instead.\n",
                        $bladestanConflict,
                    ));
                }
            }
        } catch (Throwable) {
            // Template discovery failed (e.g. no bootable app) — skip the check.
        }

        // Advisory: Bladestan is installed but `.bladestan` is not among the
        // analysed paths, so template bodies go unanalysed. Skip this when a
        // raw view directory was already flagged above (that message tells the
        // user to add `.bladestan`), and let users who only want call-site
        // validation silence it with `bladestan.reportUnanalysedTemplates`.
        if ($bladestanIsAnalyseCommand
            && $bladestanConflicts === []
            && $bladestanExtensionLoaded
            && ! $bladestanShouldCompile
            && $bladestanReportUnanalysed
        ) {
            fwrite(
                STDERR,
                'Warning: Bladestan is not analysing your Blade template bodies because ".bladestan" is missing from PHPStan "paths" (view() call sites are still checked). '
                . "Add it to analyse your templates, or set parameters.bladestan.reportUnanalysedTemplates: false to silence.\n",
            );
        }

        // Advisory: `.bladestan` is being analysed but no output format was
        // chosen, so errors will point at the compiled PHP instead of the
        // original template. Only `--error-format=blade` remaps them. Choosing
        // any format (on the CLI or in config) means the user picked their
        // output and silences this.
        $bladestanErrorFormatOnCli = false;
        foreach ($bladestanArgv as $bladestanArg) {
            if ($bladestanArg === '--error-format' || str_starts_with((string) $bladestanArg, '--error-format=')) {
                $bladestanErrorFormatOnCli = true;
                break;
            }
        }

        if ($bladestanIsAnalyseCommand
            && $bladestanShouldCompile
            && ! $bladestanErrorFormatOnCli
            && ! $bladestanErrorFormatConfigured
        ) {
            fwrite(
                STDERR,
                'Note: Bladestan is analysing ".bladestan" without "--error-format=blade", so template errors point at the compiled PHP, not your ".blade.php" files. '
                . "Pass \"--error-format=blade\" to map them back.\n",
            );
        }

        if ($bladestanShouldCompile) {
            // Phase 1: compile all blade templates to standalone PHP files.
            // Construct compiler dependencies manually (no Bladestan DI container yet).
            $simplePhpParser = new SimplePhpParser();
            $printerStandard = new Standard();
            $constExprEvaluator = new ConstExprEvaluator();
            $signatureExtractor = new SignatureExtractor();
            $arrayStringToArrayConverter = new ArrayStringToArrayConverter($printerStandard, $constExprEvaluator);
            $bladeLineNumberNodeVisitor = new BladeLineNumberNodeVisitor();
            $phpLineToTemplateLineResolver = new PhpLineToTemplateLineResolver(
                $bladeLineNumberNodeVisitor,
                $simplePhpParser
            );

            $bladeCompiler = (new BladeCompilerFactory())->create();

            $bladeToPhpCompiler = new BladeToPHPCompiler(
                $bladeCompiler,
                $printerStandard,
                new ValueResolver(),
                $phpLineToTemplateLineResolver,
                $arrayStringToArrayConverter,
                new FileNameAndLineNumberAddingPreCompiler(),
                new LivewireTagCompiler($arrayStringToArrayConverter),
                $simplePhpParser,
                $signatureExtractor,
                new ComponentScopeResolver($bladeCompiler, $arrayStringToArrayConverter),
            );

            (new TemplateCompilationBootstrap(
                $bladestanTemplateDiscovery,
                $bladeToPhpCompiler,
                $bladestanCompiledViewPath,
                getcwd() ?: '',
            ))->run();
        }
    }
}
