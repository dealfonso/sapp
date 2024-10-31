<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\For_\RemoveDeadIfForeachForRector;
use Rector\DeadCode\Rector\For_\RemoveDeadLoopRector;
use Rector\DeadCode\Rector\If_\RemoveAlwaysTrueIfConditionRector;
use Rector\DeadCode\Rector\Stmt\RemoveUnreachableStatementRector;
use Rector\DeadCode\Rector\Switch_\RemoveDuplicatedCaseInSwitchRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/bin',
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
    ->withPreparedSets(deadCode: true)
    ->withSkip([
        RemoveDeadIfForeachForRector::class,
        RemoveDeadLoopRector::class,
        RemoveDuplicatedCaseInSwitchRector::class,
        RemoveUnreachableStatementRector::class,
        RemoveAlwaysTrueIfConditionRector::class,
    ]);
