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
use Bladestan\Compiler\TypeStringValidator;
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
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\TypeParser;

if (! defined('LARAVEL_START')) {
    define('LARAVEL_START', microtime(true));
}

// PHPStan makes its DI $container available inside bootstrap files — read the
// compiled path from it so config/extension.neon stays the single source of
// truth. Template compilation is opt-in: it only runs when the compiled
// directory is among the analysed paths (the user added `.bladestan` to their
// `paths`). Without it, call-site signature validation still works and no
// compilation time is spent.
// PHPStan runs bootstrap files in every process. Derive the command and
// worker flags once, up front, from argv (which is available before the app
// boots): the loud dependency warning below needs them, and so do the later
// advisories and the compile guard. WorkerCommand and FixerWorkerCommand both
// go through CommandHelper::begin(), so only the main process reports or
// compiles; recompiling from a worker would race sibling workers analyzing the
// compiled files.
$bladestanArgv = $_SERVER['argv'] ?? [];
$bladestanIsWorkerProcess = in_array($bladestanArgv[1] ?? '', ['worker', 'fixer:worker'], true);
$bladestanIsAnalyseCommand = in_array($bladestanArgv[1] ?? '', ['analyse', 'analyze'], true);

$bladestanCompiledViewPath = getcwd() . '/.bladestan';
$bladestanShouldCompile = false;
$bladestanExtensionLoaded = false;
$bladestanReportUnanalysed = true;
$bladestanErrorFormatConfigured = false;
/** @var list<string> $bladestanAnalysedPaths */
$bladestanAnalysedPaths = [];
// Set when the compiled directory is among the analysed paths *by name* but
// resolves to a different real directory than compiledViewPath — the
// cwd-vs-config-dir divergence. Used to warn instead of silently leaving
// templates unanalysed.
$bladestanDivergentCandidate = null;
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
    } catch (Throwable) {
        // The `bladestan` parameter is absent, so the extension's config was
        // never loaded (e.g. a minimal test config that pulls in this bootstrap
        // without extension.neon). There is nothing to compile, so stay quiet.
    }

    // `analysedPaths` is an internal PHPStan container parameter
    // (parametersSchema.neon marks it "internal parameters only for
    // DerivativeContainerFactory") with no supported public alternative today.
    // We read it to decide whether `.bladestan` is among the analysed paths and
    // therefore whether to compile. If a PHPStan upgrade renames or removes it,
    // this read throws — and because a missing compile step just means no
    // template files to analyse, PHPStan would report a clean "no errors found"
    // that hides the broken dependency. Fail loudly instead, but only once the
    // extension is actually loaded (so minimal test configs stay silent).
    if ($bladestanExtensionLoaded) {
        try {
            /** @var list<string> $bladestanAnalysedPaths */
            $bladestanAnalysedPaths = $container->getParameter('analysedPaths');

            // Canonicalize a path so two spellings of the same location compare
            // equal: resolve `\` to `/`, then realpath the deepest ancestor that
            // exists and re-append the rest. The compiled directory may not exist
            // on a first run, so realpath cannot be applied to it whole; walking
            // up to the nearest existing parent still collapses symlinks and
            // `.`/`..` segments (e.g. a symlinked project root, or macOS
            // `/var` -> `/private/var`) that a raw string compare would miss.
            $bladestanCanonicalize = static function (string $path): string {
                $path = rtrim(str_replace('\\', '/', $path), '/');
                $suffix = '';
                $probe = $path;
                while ($probe !== '' && $probe !== '/') {
                    $real = realpath($probe);
                    if ($real !== false) {
                        return rtrim(str_replace('\\', '/', $real), '/') . $suffix;
                    }

                    $slash = strrpos($probe, '/');
                    if ($slash === false || $slash === 0) {
                        break;
                    }

                    $suffix = substr($probe, $slash) . $suffix;
                    $probe = substr($probe, 0, $slash);
                }

                return $path;
            };

            // Compile when the compiled directory is an analysed path *or* nested
            // inside one. PHPStan's file discovery walks into subdirectories, so
            // `paths: [build]` with `compiledViewPath: build/bladestan` analyses
            // the compiled files just as directly as listing them explicitly;
            // requiring exact equality left that output discovered but never
            // refreshed, so it went permanently stale with no warning.
            $bladestanTarget = $bladestanCanonicalize($bladestanCompiledViewPath);
            $bladestanTargetBasename = basename($bladestanTarget);
            foreach ($bladestanAnalysedPaths as $bladestanAnalysedPath) {
                $bladestanCandidate = $bladestanCanonicalize($bladestanAnalysedPath);
                if ($bladestanTarget === $bladestanCandidate
                    || str_starts_with($bladestanTarget, $bladestanCandidate . '/')
                ) {
                    $bladestanShouldCompile = true;
                    break;
                }

                // An analysed path named like the compiled directory but
                // resolving elsewhere: the user added the compiled directory to
                // `paths` (which PHPStan absolutizes against the config file)
                // while compiledViewPath absolutized against the process cwd,
                // and the two are genuinely different real directories. There is
                // no `%configDir%` neon variable to anchor both sides to, so the
                // best we can do from the bootstrap is remember the mismatch and
                // report it loudly below rather than compile into one directory
                // while PHPStan analyses the other in silence.
                if (basename($bladestanCandidate) === $bladestanTargetBasename) {
                    $bladestanDivergentCandidate = $bladestanCandidate;
                }
            }
        } catch (Throwable) {
            // Only the main analyse process warns: workers run the `worker`
            // command (not `analyse`), and repeating this from each of them
            // would bury the message. Other commands that load this bootstrap
            // (clear-result-cache, diagnose) do not compile, so the warning
            // would be noise there.
            if ($bladestanIsAnalyseCommand) {
                fwrite(
                    STDERR,
                    'Warning: Bladestan could not read PHPStan\'s internal "analysedPaths" parameter, which it relies on to decide whether to compile your Blade templates. '
                    . 'This usually means a PHPStan upgrade changed or removed it. Your template bodies will NOT be analysed, so any errors inside them are silently missing (view() call sites are still checked). '
                    . "Please report this at https://github.com/luxplus/bladestan/issues so the extension can be updated.\n",
                );
            }
        }
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

    // Only the main process may compile: it finishes before workers spawn
    // (see the worker note at the top), and recompiling from a worker would
    // race against sibling workers analyzing the compiled files.
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

        // Advisory: the compiled directory is in `paths` but resolves to a
        // different real directory than compiledViewPath. This is the
        // cwd-vs-config-dir divergence: templates are compiled into one
        // directory while PHPStan analyses the other, so nothing is refreshed
        // and no template body is analysed. It is an active misconfiguration
        // (the user did add the directory to `paths`), so warn even when
        // reportUnanalysedTemplates is off, and in place of the generic
        // "missing from paths" advisory below, which would be misleading here.
        if ($bladestanIsAnalyseCommand
            && $bladestanConflicts === []
            && $bladestanExtensionLoaded
            && ! $bladestanShouldCompile
            && $bladestanDivergentCandidate !== null
        ) {
            fwrite(
                STDERR,
                sprintf(
                    'Warning: Bladestan compiles templates to "%s", but PHPStan is analysing "%s" instead (same name, different directory), so no template body is analysed. '
                    . 'PHPStan resolves "paths" against the config file while compiledViewPath resolves against the working directory, so running PHPStan from a directory other than the one holding its config file makes the two diverge. '
                    . "Run PHPStan from the directory holding your config file, or set parameters.bladestan.compiledViewPath to an absolute path.\n",
                    $bladestanCompiledViewPath,
                    $bladestanDivergentCandidate,
                ),
            );
        }

        // Advisory: Bladestan is installed but `.bladestan` is not among the
        // analysed paths, so template bodies go unanalysed. Skip this when a
        // raw view directory was already flagged above (that message tells the
        // user to add `.bladestan`) or when the divergence advisory above
        // already explained the mismatch, and let users who only want call-site
        // validation silence it with `bladestan.reportUnanalysedTemplates`.
        if ($bladestanIsAnalyseCommand
            && $bladestanConflicts === []
            && $bladestanExtensionLoaded
            && ! $bladestanShouldCompile
            && $bladestanDivergentCandidate === null
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

            // TypeStringValidator wraps PHPStan's own phpdoc lexer/parser and
            // type resolver, so pull those from PHPStan's container rather than
            // rebuilding them (they need PHPStan's ParserConfig). $container is
            // guaranteed a PHPStan container here: $bladestanShouldCompile can
            // only be true after the container branch above resolved it.
            $typeStringValidator = new TypeStringValidator(
                $container->getByType(Lexer::class),
                $container->getByType(TypeParser::class),
                $container->getByType(TypeStringResolver::class),
            );

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
                $typeStringValidator,
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
