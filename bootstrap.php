<?php

declare(strict_types=1);

use Bladestan\Blade\PhpLineToTemplateLineResolver;
use Bladestan\Bootstrap\TemplateCompilationBootstrap;
use Bladestan\Compiler\BladeToPHPCompiler;
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
if (isset($container) && $container instanceof PHPStan\DependencyInjection\Container) {
    try {
        /** @var array{compiledViewPath: string} $bladestanParameters */
        $bladestanParameters = $container->getParameter('bladestan');
        $bladestanCompiledViewPath = $bladestanParameters['compiledViewPath'];

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

    if ($bladestanShouldCompile && ! $bladestanIsWorkerProcess) {
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

        $bladeToPhpCompiler = new BladeToPHPCompiler(
            new Filesystem(),
            (new BladeCompilerFactory())->create(),
            $printerStandard,
            new ValueResolver(),
            new VarDocNodeFactory(),
            $phpLineToTemplateLineResolver,
            $arrayStringToArrayConverter,
            new FileNameAndLineNumberAddingPreCompiler(),
            new LivewireTagCompiler($arrayStringToArrayConverter),
            $simplePhpParser,
            $signatureExtractor,
        );

        (new TemplateCompilationBootstrap(
            new TemplateDiscovery(),
            $bladeToPhpCompiler,
            $bladestanCompiledViewPath,
            getcwd() ?: '',
        ))->run();
    }
}
