<?php

return [
    'session' => [
        'ttl' => env('APERTURE_SESSION_TTL', 7200),
    ],
    'ssh_proxy' => [
        'enabled' => env('APERTURE_SSH_PROXY_ENABLED', false),
        'host' => env('APERTURE_SSH_PROXY_HOST', '127.0.0.1'),
        'port' => (int) env('APERTURE_SSH_PROXY_PORT', 8022),
        'listen_host' => env('APERTURE_SSH_PROXY_LISTEN_HOST', '0.0.0.0'),
        'api_key' => env('APERTURE_SSH_PROXY_API_KEY'),
        'keepalive_seconds' => (int) env('APERTURE_SSH_PROXY_KEEPALIVE', 300),
        'idle_timeout_seconds' => (int) env('APERTURE_SSH_PROXY_IDLE_TIMEOUT', 600),
        'sweep_interval_seconds' => (int) env('APERTURE_SSH_PROXY_SWEEP_INTERVAL', 60),
        'command_timeout_seconds' => (int) env('APERTURE_SSH_PROXY_COMMAND_TIMEOUT', 30),
        'read_timeout_seconds' => (int) env('APERTURE_SSH_PROXY_READ_TIMEOUT', 5),
        'connect_timeout' => (int) env('APERTURE_SSH_PROXY_CONNECT_TIMEOUT', 5),
        'request_timeout' => (int) env('APERTURE_SSH_PROXY_REQUEST_TIMEOUT', 60),
    ],
    'switch_sync_interval' => (int) env('APERTURE_SWITCH_SYNC_INTERVAL', 5),
    'circuit_breaker' => [
        'failure_threshold' => (int) env('APERTURE_CIRCUIT_BREAKER_THRESHOLD', 3),
    ],
    'bandwidth_anomaly' => [
        'threshold' => (float) env('APERTURE_BANDWIDTH_ANOMALY_THRESHOLD', 3.0),
    ],
    'theme' => [
        'mode' => 'dark',
        'accent_hue' => 55,
        'accent_chroma' => 0.19,
        'accent_lightness' => 72,
    ],
];
