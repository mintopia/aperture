<?php

return [
    'opnsense' => [
        'name' => 'OPNsense',
        'description' => 'Network firewall providing captive portal, rate limiting, and DHCP services.',
        'capabilities' => ['captive-portal', 'firewall', 'rate-limiting', 'dhcp'],
        'validation' => [
            'endpoint' => 'nullable|url|max:500',
            'key' => 'nullable|string|max:500',
            'secret' => 'nullable|string|max:500',
            'captive_portal_id' => 'nullable|string|max:100',
            'verify_ssl' => 'nullable|string|in:0,1',
            'zone_id' => 'nullable|string|max:100',
            'ratelimit_up_uuid' => 'nullable|string|max:500',
            'ratelimit_down_uuid' => 'nullable|string|max:500',
        ],
    ],
    'librenms' => [
        'name' => 'LibreNMS',
        'description' => 'Network monitoring for IP/MAC resolution, port mapping, and bandwidth data.',
        'capabilities' => ['ip-to-mac', 'mac-to-port', 'port-bandwidth', 'device-list'],
        'validation' => [
            'endpoint' => 'nullable|url|max:500',
            'api_key' => 'nullable|string|max:500',
            'enabled' => 'nullable|string|in:0,1',
        ],
    ],
    'ntopng' => [
        'name' => 'ntopng',
        'description' => 'Traffic analysis providing per-user bandwidth metrics and top talker data.',
        'capabilities' => ['user-bandwidth', 'top-talkers', 'aggregate-stats'],
        'validation' => [
            'endpoint' => 'nullable|url|max:500',
            'username' => 'nullable|string|max:255',
            'password' => 'nullable|string|max:500',
            'interface' => 'nullable|string|max:100',
            'enabled' => 'nullable|string|in:0,1',
        ],
    ],
    'pihole' => [
        'name' => 'Pi-hole',
        'description' => 'DNS filtering and optional DHCP/IP-to-MAC resolution.',
        'capabilities' => ['dns-filtering', 'dhcp', 'ip-to-mac'],
        'validation' => [
            'endpoint' => 'nullable|url|max:500',
            'password' => 'nullable|string|max:500',
            'noblock_group_id' => 'nullable|integer|min:1',
            'enabled' => 'nullable|string|in:0,1',
            'verify_ssl' => 'nullable|string|in:0,1',
        ],
    ],
];
