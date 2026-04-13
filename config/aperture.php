<?php

return [
    'cisco' => [
        'hostname' => env('APERTURE_CISCO_HOSTNAME'),
        'username' => env('APERTURE_CISCO_USERNAME'),
        'password' => env('APERTURE_CISCO_PASSWORD'),
        'enablePassword' => env('APERTURE_CISCO_ENABLE_PASSWORD'),
        'timeout' => env('APERTURE_CISCO_TIMEOUT', 5),
    ],
    'borealis' => [
        'enabled' => env('BOREALIS_ENABLED', false),
        'endpoint' => env('BOREALIS_ENDPOINT'),
        'client_id' => env('BOREALIS_CLIENT_ID'),
        'client_secret' => env('BOREALIS_CLIENT_SECRET'),
        'scope' => env('BOREALIS_SCOPE', 'discord'),
    ],
    'session' => [
        'ttl' => env('APERTURE_SESSION_TTL', 7200),
    ],
    'ssh_proxy' => [
        'enabled' => env('APERTURE_SSH_PROXY_ENABLED', false),
        'host' => env('APERTURE_SSH_PROXY_HOST', '127.0.0.1'),
        'port' => (int) env('APERTURE_SSH_PROXY_PORT', 8022),
        'api_key' => env('APERTURE_SSH_PROXY_API_KEY'),
        'keepalive_seconds' => (int) env('APERTURE_SSH_PROXY_KEEPALIVE', 300),
        'idle_timeout_seconds' => (int) env('APERTURE_SSH_PROXY_IDLE_TIMEOUT', 600),
        'sweep_interval_seconds' => (int) env('APERTURE_SSH_PROXY_SWEEP_INTERVAL', 60),
        'command_timeout_seconds' => (int) env('APERTURE_SSH_PROXY_COMMAND_TIMEOUT', 30),
        'read_timeout_seconds' => (int) env('APERTURE_SSH_PROXY_READ_TIMEOUT', 5),
    ],
];
