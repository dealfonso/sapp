<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
    ])
    // uncomment to reach your current PHP version
    ->withPhpSets(php80: true)
    ->withImportNames()
    ->withParallel(jobSize: 2)
//    ->withTypeCoverageLevel(1)
//    ->withDeadCodeLevel(1)
//    ->withPreparedSets(deadCode: true, codeQuality: true, codingStyle: true, typeDeclarations: true)
    ->withPreparedSets(typeDeclarations: true)
    ->withPreparedSets(codeQuality: true)
    ->withPreparedSets(codingStyle: true)
    ;
