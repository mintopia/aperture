<?php

return [
    'opnsense' => [
        'name' => 'OPNsense',
        'description' => 'Network firewall providing captive portal, rate limiting, and DHCP services.',
        'capabilities' => ['captive-portal', 'firewall', 'rate-limiting', 'dhcp'],
        'fields' => [
            // Connection settings
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
            'verify_ssl' => [
                'type' => 'toggle',
                'label' => 'Verify SSL',
                'help' => 'Verify the SSL certificate when connecting.',
            ],
            // Portal / zone settings
            'captive_portal_id' => [
                'type' => 'select-remote',
                'label' => 'Captive Portal Zone',
                'placeholder' => 'Select a zone…',
                'help' => 'The captive portal zone to use.',
                'remote_url' => '/admin/settings/integrations/opnsense/zones',
                'remote_label' => 'name',
                'remote_value' => 'id',
            ],
            'zone_id' => [
                'type' => 'select-remote',
                'label' => 'Firewall Zone',
                'placeholder' => 'Select a zone…',
                'help' => 'The firewall zone ID for captive portal operations.',
                'remote_url' => '/admin/settings/integrations/opnsense/zones',
                'remote_label' => 'name',
                'remote_value' => 'id',
            ],
            // Rate limiting – dynamic dropdowns
            'ratelimit_up_uuid' => [
                'type' => 'select-remote',
                'label' => 'Upload Rate Limit Rule',
                'placeholder' => 'Select a shaper rule…',
                'help' => 'Traffic shaper rule for upload rate limiting. Select "None" to disable.',
                'remote_url' => '/admin/settings/integrations/opnsense/shaper-rules',
                'remote_label' => 'description',
                'remote_value' => 'uuid',
            ],
            'ratelimit_down_uuid' => [
                'type' => 'select-remote',
                'label' => 'Download Rate Limit Rule',
                'placeholder' => 'Select a shaper rule…',
                'help' => 'Traffic shaper rule for download rate limiting. Select "None" to disable.',
                'remote_url' => '/admin/settings/integrations/opnsense/shaper-rules',
                'remote_label' => 'description',
                'remote_value' => 'uuid',
            ],
            // DHCP settings
            'dhcp_server' => [
                'type' => 'select',
                'label' => 'DHCP Server',
                'help' => 'Select the DHCP server plugin installed on OPNsense. Set to None if DHCP is not managed by OPNsense.',
                'options' => [
                    '' => 'None',
                    'isc' => 'ISC DHCPD',
                    'kea' => 'Kea DHCP',
                    'dnsmasq' => 'Dnsmasq',
                ],
            ],
        ],
        'validation' => [
            'endpoint' => 'nullable|url|max:500',
            'key' => 'nullable|string|max:500',
            'secret' => 'nullable|string|max:500',
            'verify_ssl' => 'nullable|string|in:0,1',
            'captive_portal_id' => 'nullable|string|max:100',
            'zone_id' => 'nullable|string|max:100',
            'ratelimit_up_uuid' => 'nullable|string|max:500',
            'ratelimit_down_uuid' => 'nullable|string|max:500',
            'dhcp_server' => 'nullable|string|in:,isc,kea,dnsmasq',
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
            'filtered_group_id' => [
                'type' => 'select-remote',
                'label' => 'Filtered Group',
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
            'filtered_group_id' => 'nullable|integer|min:0',
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
    'prometheus' => [
        'name' => 'Prometheus',
        'description' => 'Time-series metrics database for network bandwidth and device monitoring.',
        'capabilities' => ['port-bandwidth', 'port-errors', 'device-metrics', 'aggregate-stats', 'user-bandwidth'],
        'fields' => [
            'endpoint' => [
                'type' => 'url',
                'label' => 'Prometheus URL',
                'placeholder' => 'https://prometheus.local:9090',
                'help' => 'Base URL of your Prometheus instance.',
            ],
            'bearer_token' => [
                'type' => 'password',
                'label' => 'Bearer Token',
                'help' => 'Optional bearer token for authentication.',
            ],
            'verify_ssl' => [
                'type' => 'toggle',
                'label' => 'Verify SSL',
                'help' => 'Verify the SSL certificate when connecting.',
            ],
            'default_step' => [
                'type' => 'text',
                'label' => 'Default Step',
                'placeholder' => '60',
                'help' => 'Default query step interval in seconds.',
            ],
            'enabled' => [
                'type' => 'toggle',
                'label' => 'Enabled',
                'help' => 'Enable or disable this integration.',
            ],
            'bandwidth_rcvd_metric' => [
                'type' => 'text',
                'label' => 'Receive Metric',
                'placeholder' => 'ntopng_host_bytes_rcvd',
                'help' => 'Prometheus metric name for received/download bytes per IP.',
            ],
            'bandwidth_sent_metric' => [
                'type' => 'text',
                'label' => 'Send Metric',
                'placeholder' => 'ntopng_host_bytes_sent',
                'help' => 'Prometheus metric name for sent/upload bytes per IP.',
            ],
            'bandwidth_ip_label' => [
                'type' => 'text',
                'label' => 'IP Label',
                'placeholder' => 'ip',
                'help' => 'Prometheus label name that contains the IP address.',
            ],
        ],
        'validation' => [
            'endpoint' => 'nullable|url|max:500',
            'bearer_token' => 'nullable|string|max:500',
            'verify_ssl' => 'nullable|string|in:0,1',
            'default_step' => 'nullable|numeric|min:1|max:3600',
            'enabled' => 'nullable|string|in:0,1',
            'bandwidth_rcvd_metric' => 'nullable|string|max:255',
            'bandwidth_sent_metric' => 'nullable|string|max:255',
            'bandwidth_ip_label' => 'nullable|string|max:100',
        ],
    ],
];
