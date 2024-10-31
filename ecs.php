<?php

declare(strict_types=1);

use PhpCsFixer\Fixer\Import\NoUnusedImportsFixer;
use Symplify\EasyCodingStandard\Config\ECSConfig;

return ECSConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
    ])

    // add a single rule
    ->withRules([
        NoUnusedImportsFixer::class,
    ])
    ->withPreparedSets(
        arrays: true,
        controlStructures: true,
        psr12: true,
        comments: true,
//        docblocks: true,
        spaces: true,
        namespaces: true,
    );
