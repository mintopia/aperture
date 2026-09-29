<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\SetList;
use RectorLaravel\Rector\ArrayDimFetch\EnvVariableToEnvHelperRector;
use RectorLaravel\Rector\ArrayDimFetch\ServerVariableToRequestFacadeRector;
use RectorLaravel\Rector\MethodCall\AssertSeeToAssertSeeHtmlRector;
use RectorLaravel\Rector\MethodCall\EloquentOrderByToLatestOrOldestRector;
use RectorLaravel\Rector\MethodCall\ValidationRuleArrayStringValueToArrayRector;
use RectorLaravel\Set\LaravelLevelSetList;
use RectorLaravel\Set\LaravelSetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/tests',
    ])
    ->withSkipPath(__DIR__.'/vendor')
    ->withSets([
        SetList::PHP_84,
        SetList::CODE_QUALITY,
        SetList::CODING_STYLE,
        SetList::DEAD_CODE,
        SetList::TYPE_DECLARATION,
        LaravelLevelSetList::UP_TO_LARAVEL_130,
        LaravelSetList::LARAVEL_CODE_QUALITY,
        LaravelSetList::LARAVEL_COLLECTION,
    ])
    ->withSkip([
        ValidationRuleArrayStringValueToArrayRector::class,
        EloquentOrderByToLatestOrOldestRector::class,
        EnvVariableToEnvHelperRector::class,
        ServerVariableToRequestFacadeRector::class,
        AssertSeeToAssertSeeHtmlRector::class,
    ])
    ->withImportNames();
