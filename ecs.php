<?php

declare(strict_types=1);

use PhpCsFixer\Fixer\Import\NoUnusedImportsFixer;
use Symplify\CodingStandard\Fixer\Commenting\ParamReturnAndVarTagMalformsFixer;
use Symplify\CodingStandard\Fixer\Commenting\RemoveUselessDefaultCommentFixer;
use Symplify\EasyCodingStandard\Config\ECSConfig;

return ECSConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/bin',
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
        docblocks: true,
        spaces: true,
        cleanCode: true,
        namespaces: true,
    )
    ->withSkip([
        RemoveUselessDefaultCommentFixer::class,
        ParamReturnAndVarTagMalformsFixer::class,
    ]);
