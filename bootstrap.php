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
use Bladestan\TemplateCompiler\NodeFactory\VarDocNodeFactory;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
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
/** @var list<string> $bladestanAnalysedPaths */
$bladestanAnalysedPaths = [];
if (isset($container) && $container instanceof PHPStan\DependencyInjection\Container) {
    try {
        /** @var array{compiledViewPath: string, reportUnanalysedTemplates?: bool} $bladestanParameters */
        $bladestanParameters = $container->getParameter('bladestan');
        $bladestanCompiledViewPath = $bladestanParameters['compiledViewPath'];
        $bladestanExtensionLoaded = true;
        $bladestanReportUnanalysed = $bladestanParameters['reportUnanalysedTemplates'] ?? true;

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

            foreach ($bladestanConflicts as $bladestanConflict) {
                fwrite(STDERR, sprintf(
                    "Bladestan: the analysed path \"%s\" contains raw Blade templates.\n"
                    . "PHPStan parses .blade.php files as plain PHP, so any errors from them do not reflect your templates.\n"
                    . 'Remove this path from PHPStan "paths" and add ".bladestan" instead. '
                    . "Bladestan compiles your templates there and analyses them against their signatures.\n",
                    $bladestanConflict,
                ));
            }
        } catch (Throwable) {
            // Template discovery failed (e.g. no bootable app) — skip the check.
        }

        // Advisory: Bladestan is installed but `.bladestan` is not among the
        // analysed paths, so template bodies go unanalysed. Skip this when a
        // raw view directory was already flagged above (that message tells the
        // user to add `.bladestan`), and let users who only want call-site
        // validation silence it with `bladestan.reportUnanalysedTemplates`.
        if ($bladestanConflicts === []
            && $bladestanExtensionLoaded
            && ! $bladestanShouldCompile
            && $bladestanReportUnanalysed
        ) {
            fwrite(
                STDERR,
                "Bladestan: \".bladestan\" is not among PHPStan's analysed paths, so your Blade template bodies are not analysed (view() call sites are still checked).\n"
                . "Add \".bladestan\" to \"paths\" in your PHPStan config to analyse your templates.\n"
                . "If you only want call-site validation, set parameters.bladestan.reportUnanalysedTemplates to false to silence this message.\n",
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
                new Filesystem(),
                $bladeCompiler,
                $printerStandard,
                new ValueResolver(),
                new VarDocNodeFactory(),
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
