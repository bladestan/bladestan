<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;
use Rector\PHPUnit\Set\PHPUnitSetList;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        // configs
        __DIR__ . '/ecs.php',
        __DIR__ . '/rector.php',
    ])
    ->withImportNames()
    ->withSkip([
        '*/Fixture/*',
        // test assertions use raw PHPDoc type strings (e.g. '\App\Models\User'), not class references
        StringClassNameToClassConstantRector::class => [
            __DIR__ . '/tests/Compiler/SignatureExtractorTest.php',
            // PHPStan's worker runners are named, not referenced: they carry no
            // backward-compatibility promise, and a ::class constant would make
            // Bladestan depend on internals it only ever compares against.
            __DIR__ . '/src/Bootstrap/ForkedWorkerDetector.php',
            __DIR__ . '/tests/Bootstrap/ForkedWorkerDetectorTest.php',
        ],
    ])
    ->withPhpVersion(PhpVersion::PHP_81)
    ->withSets([PHPUnitSetList::COMPOSER_BASED])
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations : true,
        privatization : true,
        naming : true,
        earlyReturn : true,
    );
