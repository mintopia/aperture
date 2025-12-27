<?php
return [
    'cisco' => [
        'username' => env('APERTURE_CISCO_USERNAME'),
        'password' => env('APERTURE_CISCO_PASSWORD'),
        'enablePassword' => env('APERTURE_CISCO_ENABLE_PASSWORD'),
        'timeout' => env('APERTURE_CISCO_TIMEOUT', 5),
    ],
    'opnsense' => [
        'endpoint' => env('APERTURE_OPNSENSE_ENDPOINT'),
        'key' => env('APERTURE_OPNSENSE_KEY'),
        'secret' => env('APERTURE_OPNSENSE_SECRET'),
        'zoneid' => env('APERTURE_OPNSENSE_ZONEID'),
        'verify' => env('APERTURE_OPNSENSE_VERIFY', true),
        'ratelimitUpUuid' => env('APERTURE_OPNSENSE_RATELIMIT_RULE_UP_UUID'),
        'ratelimitDownUuid' => env('APERTURE_OPNSENSE_RATELIMIT_RULE_DOWN_UUID'),
    ],
    'lnms' => [
        'enabled' => env('APERTURE_LNMS_ENABLED'),
    ],
    'ntopng' => [
        'enabled' => env('APERTURE_NTOPNG_ENABLED'),
        'endpoint' => env('APERTURE_NTOPNG_ENDPOINT'),
        'username' => env('APERTURE_NTOPNG_USERNAME'),
        'password' => env('APERTURE_NTOPNG_PASSWORD'),
        'interface' => env('APERTURE_NTOPNG_INTERFACE'),
    ],
    'borealis' => [
        'enabled' => env('BOREALIS_ENABLED', false),
        'endpoint' => env('BOREALIS_ENDPOINT'),
        'client_id' => env('BOREALIS_CLIENT_ID'),
        'client_secret' => env('BOREALIS_CLIENT_SECRET'),
    ],
];
