<?php

return [
    // Idle timeout in seconds before closing unused connections
    'idle_timeout' => env('SSH_DAEMON_IDLE_TIMEOUT', 600),

    // Sweep interval for background cleanup
    'sweep_interval' => env('SSH_DAEMON_SWEEP_INTERVAL', 60),

    // If you run the daemon as a separate process and want clients to call it,
    // set the internal URL and a shared secret token.
    'daemon_url' => env('SSH_DAEMON_URL', 'http://ssh-daemon:9022'),
    'daemon_token' => env('SSH_DAEMON_TOKEN', null),

    // If true, bind SSHPool into the container for in-process usage
    'bind_local_pool' => env('SSH_DAEMON_BIND_LOCAL_POOL', false),

    // Default socket path if using a unix domain socket (optional)
    'socket_path' => env('SSH_DAEMON_SOCKET', null),

    // Optional logging channel (Laravel log channel name) - defaults to Laravel app logger when null
    'log_channel' => env('SSH_DAEMON_LOG_CHANNEL', null),

    // Optional log formatter: 'json' for Monolog\Formatter\JsonFormatter or a full formatter class name
    'log_formatter' => env('SSH_DAEMON_LOG_FORMATTER', null),
];
