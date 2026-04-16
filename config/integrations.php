<?php

return [
    'opnsense' => [
        'name' => 'OPNsense',
        'description' => 'Network firewall providing captive portal, rate limiting, and DHCP services.',
        'capabilities' => ['captive-portal', 'firewall', 'rate-limiting', 'dhcp'],
        'fields' => [
            'endpoint' => [
                'type' => 'url',
                'label' => 'API Endpoint',
                'placeholder' => 'https://opnsense.local/api',
                'help' => 'Base URL of your OPNsense installation.',
            ],
            'key' => [
                'type' => 'password',
                'label' => 'API Key',
                'help' => 'OPNsense API key for authentication.',
            ],
            'secret' => [
                'type' => 'password',
                'label' => 'API Secret',
                'help' => 'OPNsense API secret for authentication.',
            ],
            'captive_portal_id' => [
                'type' => 'text',
                'label' => 'Captive Portal Zone ID',
                'placeholder' => '0',
                'help' => 'The numeric zone ID for the captive portal.',
            ],
            'zone_id' => [
                'type' => 'text',
                'label' => 'Firewall Zone ID',
                'placeholder' => '0',
                'help' => 'The numeric firewall zone ID.',
            ],
            'verify_ssl' => [
                'type' => 'toggle',
                'label' => 'Verify SSL',
                'help' => 'Verify the SSL certificate when connecting.',
            ],
            'ratelimit_up_uuid' => [
                'type' => 'text',
                'label' => 'Upload Rate Limit Rule UUID',
                'help' => 'UUID of the traffic shaper pipe for upload limiting.',
            ],
            'ratelimit_down_uuid' => [
                'type' => 'text',
                'label' => 'Download Rate Limit Rule UUID',
                'help' => 'UUID of the traffic shaper pipe for download limiting.',
            ],
        ],
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
        'fields' => [
            'endpoint' => [
                'type' => 'url',
                'label' => 'API Endpoint',
                'placeholder' => 'https://librenms.local/api/v0',
                'help' => 'Base URL of the LibreNMS API (v0).',
            ],
            'api_key' => [
                'type' => 'password',
                'label' => 'API Key',
                'help' => 'LibreNMS API token for authentication.',
            ],
            'enabled' => [
                'type' => 'toggle',
                'label' => 'Enabled',
                'help' => 'Enable or disable this integration.',
            ],
        ],
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
        'fields' => [
            'endpoint' => [
                'type' => 'url',
                'label' => 'API Endpoint',
                'placeholder' => 'https://ntopng.local:3000',
                'help' => 'Base URL of your ntopng instance.',
            ],
            'username' => [
                'type' => 'text',
                'label' => 'Username',
                'placeholder' => 'admin',
                'help' => 'Username for ntopng authentication.',
            ],
            'password' => [
                'type' => 'password',
                'label' => 'Password',
                'help' => 'Password for ntopng authentication.',
            ],
            'interface' => [
                'type' => 'text',
                'label' => 'Interface',
                'placeholder' => '0',
                'help' => 'Network interface index to monitor.',
            ],
            'enabled' => [
                'type' => 'toggle',
                'label' => 'Enabled',
                'help' => 'Enable or disable this integration.',
            ],
        ],
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
        'fields' => [
            'endpoint' => [
                'type' => 'url',
                'label' => 'API Endpoint',
                'placeholder' => 'https://pihole.local',
                'help' => 'Base URL of your Pi-hole admin interface.',
            ],
            'password' => [
                'type' => 'password',
                'label' => 'API Password',
                'help' => 'Pi-hole admin password or app password.',
            ],
            'noblock_group_id' => [
                'type' => 'select-remote',
                'label' => 'Blocking Group',
                'placeholder' => 'Select a group…',
                'help' => 'Pi-hole group ID for clients that should have ad blocking enabled.',
                'remote_url' => '/admin/settings/integrations/pihole/groups',
                'remote_label' => 'name',
                'remote_value' => 'id',
            ],
            'verify_ssl' => [
                'type' => 'toggle',
                'label' => 'Verify SSL',
                'help' => 'Verify the SSL certificate when connecting.',
            ],
            'enabled' => [
                'type' => 'toggle',
                'label' => 'Enabled',
                'help' => 'Enable or disable this integration.',
            ],
        ],
        'validation' => [
            'endpoint' => 'nullable|url|max:500',
            'password' => 'nullable|string|max:500',
            'noblock_group_id' => 'nullable|integer|min:0',
            'enabled' => 'nullable|string|in:0,1',
            'verify_ssl' => 'nullable|string|in:0,1',
        ],
    ],
    'borealis' => [
        'name' => 'Borealis',
        'description' => 'OAuth2 authentication provider for user login via device code flow.',
        'capabilities' => ['authentication', 'sso', 'user-info'],
        'fields' => [
            'endpoint' => [
                'type' => 'url',
                'label' => 'OAuth2 Endpoint',
                'placeholder' => 'https://auth.borealis.example.com',
                'help' => 'Base URL of the Borealis OAuth2 server.',
            ],
            'client_id' => [
                'type' => 'text',
                'label' => 'Client ID',
                'placeholder' => '',
                'help' => 'OAuth2 client ID for this application.',
            ],
            'client_secret' => [
                'type' => 'password',
                'label' => 'Client Secret',
                'placeholder' => '',
                'help' => 'OAuth2 client secret for this application.',
            ],
            'scope' => [
                'type' => 'text',
                'label' => 'Scope',
                'placeholder' => 'discord',
                'help' => 'OAuth2 scope to request during device flow.',
            ],
        ],
        'validation' => [
            'endpoint' => 'nullable|url|max:500',
            'client_id' => 'nullable|string|max:500',
            'client_secret' => 'nullable|string|max:500',
            'scope' => 'nullable|string|max:255',
        ],
    ],
];
