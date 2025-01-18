<?php

declare(strict_types=1);

use PhpCsFixer\Fixer\Phpdoc\GeneralPhpdocAnnotationRemoveFixer;
use Symplify\EasyCodingStandard\Config\ECSConfig;

return ECSConfig::configure()
    ->withPaths([__DIR__ . '/src', __DIR__ . '/tests'])
    ->withRootFiles()
    ->withConfiguredRule(
        GeneralPhpdocAnnotationRemoveFixer::class,
        [
            'annotations' => ['author', 'package', 'group', 'covers', 'category'],
        ] // Allow @throws
    )
    ->withPreparedSets(psr12: true, common: true, symplify: true)
    ->withSkip(['*/Fixture/*']);
