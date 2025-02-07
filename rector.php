<?php

declare(strict_types=1);

use Rector\CodingStyle\Rector\Encapsed\EncapsedStringsToSprintfRector;
use Rector\Config\RectorConfig;
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
        EncapsedStringsToSprintfRector::class, // sprint hurts performance noticeably
    ])
    ->withPhpVersion(PhpVersion::PHP_81)
    ->withSets([PHPUnitSetList::PHPUNIT_100])
    ->withPreparedSets(
        codingStyle: true,
        codeQuality: true,
        deadCode: true,
        earlyReturn : true,
        privatization : true,
        naming : true,
        typeDeclarations : true,
    );
