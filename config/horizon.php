<?php

use Illuminate\Support\Str;

return [

    'domain' => env('HORIZON_DOMAIN'),

    'path' => env('HORIZON_PATH', 'horizon'),

    'use' => 'default',

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    'middleware' => ['web'],

    'waits' => [
        'redis:default' => 60,
    ],

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    'silenced' => [],

    'silenced_tags' => [],

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    'fast_termination' => false,

    'memory_limit' => 64,

    'defaults' => [
        'supervisor-access' => [
            'connection' => 'redis',
            'queue' => ['access', 'default'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 60,
            'nice' => 0,
        ],
        'supervisor-switch' => [
            'connection' => 'redis',
            'queue' => ['switch'],
            'balance' => 'simple',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 150,
            'nice' => 0,
        ],
        'supervisor-sync' => [
            'connection' => 'redis',
            'queue' => ['sync'],
            'balance' => 'simple',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 150,
            'nice' => 0,
        ],
    ],

    'environments' => [
        'production' => [
            'supervisor-access' => [
                'maxProcesses' => 6,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
            'supervisor-switch' => [
                'maxProcesses' => 2,
            ],
            'supervisor-sync' => [
                'maxProcesses' => 2,
            ],
        ],

        'local' => [
            'supervisor-access' => [
                'maxProcesses' => 2,
            ],
            'supervisor-switch' => [
                'maxProcesses' => 1,
            ],
            'supervisor-sync' => [
                'maxProcesses' => 1,
            ],
        ],
    ],
];
