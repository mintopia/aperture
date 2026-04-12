<?php

return [
    'cisco' => [
        'hostname' => env('APERTURE_CISCO_HOSTNAME'),
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
    'session' => [
        'ttl' => env('APERTURE_SESSION_TTL', 7200),
    ],
    'dhcp' => [
        'enabled' => env('APERTURE_DHCP_ENABLED', false),
        'endpoint' => env('APERTURE_DHCP_ENDPOINT'),
        'key' => env('APERTURE_DHCP_KEY'),
        'secret' => env('APERTURE_DHCP_SECRET'),
        'verify' => env('APERTURE_DHCP_VERIFY', true),
        'pool_size' => env('APERTURE_DHCP_POOL_SIZE', 254),
    ],
    'pihole' => [
        'enabled' => env('APERTURE_PIHOLE_ENABLED', false),
        'endpoint' => env('APERTURE_PIHOLE_ENDPOINT'),
        'password' => env('APERTURE_PIHOLE_PASSWORD'),
        'noblock_group_id' => env('APERTURE_PIHOLE_NOBLOCK_GROUP_ID', 1),
        'verify' => env('APERTURE_PIHOLE_VERIFY', true),
    ],
    'dns' => [
        'expected_server' => env('APERTURE_DNS_EXPECTED_SERVER'),
        'probe_domain' => env('APERTURE_DNS_PROBE_DOMAIN'),
    ],

    'auto_allow' => [
        'enabled' => env('APERTURE_AUTO_ALLOW_ENABLED', false),
        'oui_prefixes' => array_filter(
            explode(',', env('APERTURE_AUTO_ALLOW_OUI_PREFIXES', '98:5F:D3,7C:ED:8D,00:50:F2,28:18:78,C8:3F:26,60:45:BD,94:9A:A9,48:4D:7E,B4:09:31,DC:B4:C4')),
        ),
        'scan_interval' => (int) env('APERTURE_AUTO_ALLOW_SCAN_INTERVAL', 5),
    ],

    'ipv6' => [
        'detection_enabled' => env('APERTURE_IPV6_DETECTION_ENABLED', false),
        'detection_endpoint' => env('APERTURE_IPV6_DETECTION_ENDPOINT'),
    ],
];
