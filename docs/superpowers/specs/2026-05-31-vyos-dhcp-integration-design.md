# VyOS DHCP/DHCPv6 + IP-MAC Integration

## Overview

Add VyOS as a new integration providing DHCP, DHCPv6, and IP-MAC resolution capabilities via the VyOS HTTP API. Follows the existing capability-driven integration architecture with a shared client for future VyOS capability expansion.

## Capabilities Provided

- **DHCP** (`Capability::Dhcp`) — DHCPv4 and DHCPv6 lease listing, range/subnet enumeration, pool utilisation
- **IP-MAC** (`Capability::IpMac`) — IPv4 ARP table and IPv6 neighbor resolution

## VyOS HTTP API

VyOS (1.3+) exposes an HTTP API. All requests are POST with form-encoded body:

- `key` — API key string
- `data` — JSON string describing the operation

Two main endpoints:

- `/retrieve` — reads configuration (equivalent to `show configuration`)
- `/show` — runs operational commands (equivalent to `show` in CLI)

Example request:
```
POST https://vyos.example.com/retrieve
Content-Type: application/x-www-form-urlencoded

data={"op":"showConfig","path":["service","dhcp-server"]}&key=MY_API_KEY
```

Example response:
```json
{
  "success": true,
  "data": { ... },
  "error": null
}
```

Authentication is via API key only (no username/password). SSL verification is configurable.

## Architecture

### New Files

| File | Purpose |
|------|---------|
| `app/Services/VyOs/VyOsClient.php` | Shared HTTP client wrapping VyOS API communication |
| `app/Services/VyOs/VyOsDhcpService.php` | Implements `DhcpInterface` for VyOS DHCP/DHCPv6 |
| `app/Services/VyOs/VyOsIpMacResolver.php` | Implements `IpMacResolverInterface` via VyOS ARP/neighbor tables |
| `app/Integration/VyOsBootstrapper.php` | Registers DHCP and IP-MAC capability bindings |
| `app/Services/Integration/VyOsTester.php` | Connection tester for admin UI |

### Modified Files

| File | Change |
|------|--------|
| `app/Enums/Integration.php` | Add `case VyOs = 'vyos'` |
| `config/integrations.php` | Add `'vyos'` configuration block |
| `app/Providers/IntegrationServiceProvider.php` | Register VyOS client singleton, bootstrapper, and tester |

### No New Migrations

The existing `capability_assignments` and `integration_configs` tables handle VyOS automatically.

## Component Details

### VyOsClient

Shared singleton registered in `IntegrationServiceProvider::registerSharedSingletons()`.

```php
final class VyOsClient
{
    public function __construct(
        private string $endpoint,
        private string $apiKey,
        private bool $verifySsl = true,
    ) {}

    // Retrieve configuration data
    public function retrieve(array $path): array;

    // Run operational show command
    public function show(array $path): array;
}
```

- Uses Laravel `Http` facade for requests
- All requests POST to `$endpoint/retrieve` or `$endpoint/show`
- Body: form-encoded `data` (JSON of `{"op": "showConfig"|"show", "path": [...]}`) and `key`
- Returns decoded `data` field from response
- Throws on non-success responses

### VyOsDhcpService

Implements `DhcpInterface`.

**`getLeases(): Collection<int, DhcpLease>`**

1. Call `$client->show(["dhcp", "server", "leases"])` for DHCPv4 leases
2. Call `$client->show(["dhcpv6", "server", "leases"])` for DHCPv6 leases
3. Map each entry to `DhcpLease(ip, mac, hostname, expires)`
4. Merge and return

VyOS lease response format (DHCPv4):
```json
{
  "192.168.1.100": {
    "hardware_address": "aa:bb:cc:dd:ee:ff",
    "hostname": "workstation1",
    "expires": "2026-05-31T12:00:00"
  }
}
```

VyOS lease response format (DHCPv6):
```json
{
  "2001:db8::100": {
    "iaid_duid": "...",
    "last_communication": "2026-05-31T11:00:00",
    "expires": "2026-05-31T12:00:00",
    "type": "ia-na"
  }
}
```

Note: DHCPv6 leases may not include a MAC address directly. In that case, the MAC will be set to an empty string.

**`getLease(string $ipAddress): ?DhcpLease`**

Filters `getLeases()` result by IP address.

**`getRanges(): Collection<int, DhcpRange>`**

1. Call `$client->retrieve(["service", "dhcp-server", "shared-network-name"])` for DHCPv4 config
2. Call `$client->retrieve(["service", "dhcpv6-server", "shared-network-name"])` for DHCPv6 config
3. Parse subnet definitions from the nested config structure
4. Map to `DhcpRange` value objects
5. Enrich with usage data from leases

VyOS DHCPv4 config structure:
```json
{
  "MY_NETWORK": {
    "subnet": {
      "192.168.1.0/24": {
        "range": {
          "POOL1": {
            "start": "192.168.1.100",
            "stop": "192.168.1.200"
          }
        },
        "default-router": "192.168.1.1"
      }
    }
  }
}
```

VyOS DHCPv6 config structure:
```json
{
  "MY_V6_NETWORK": {
    "subnet": {
      "2001:db8::/64": {
        "address-range": {
          "start": {
            "2001:db8::100": {
              "stop": "2001:db8::200"
            }
          }
        }
      }
    }
  }
}
```

**`getPoolStatus(): DhcpPoolStatus`**

Counts active leases against the configured `pool_size` setting (stored in `integration_configs`).

### VyOsIpMacResolver

Implements `IpMacResolverInterface`.

**`getArpTable(): Collection<int, ArpEntry>`**

1. Call `$client->show(["ip", "neighbors"])` for IPv4 ARP entries
2. Call `$client->show(["ipv6", "neighbors"])` for IPv6 neighbor entries
3. Map each to `ArpEntry(ip, mac)`
4. Deduplicate by `ip|mac` pair

VyOS neighbor response format:
```json
[
  {
    "ip": "192.168.1.100",
    "mac": "aa:bb:cc:dd:ee:ff",
    "interface": "eth0",
    "state": "reachable"
  }
]
```

### VyOsBootstrapper

Implements `IntegrationBootstrapper`.

```php
final class VyOsBootstrapper implements IntegrationBootstrapper
{
    public function register(Application $app): void
    {
        // dhcp
        $app->bind(function (Application $app): DhcpInterface {
            if ($this->isActive(Capability::Dhcp->value)) {
                return new VyOsDhcpService(
                    $app->make(VyOsClient::class),
                    (int) IntegrationConfig::getValue(Integration::VyOs->value, 'pool_size', '0'),
                );
            }
            return new NullDhcpService;
        });

        // ip-mac
        $app->bind(function (Application $app): IpMacResolverInterface {
            if ($this->isActive(Capability::IpMac->value)) {
                return new VyOsIpMacResolver(
                    $app->make(VyOsClient::class),
                );
            }
            return new NullIpMacResolver;
        });
    }

    private function isActive(string $capability): bool
    {
        try {
            return CapabilityAssignment::isActiveProvider(
                Integration::VyOs->value, $capability
            );
        } catch (Throwable) {
            return false;
        }
    }
}
```

### VyOsTester

Implements `TestableIntegration`.

```php
class VyOsTester implements TestableIntegration
{
    public function connect(array $config): TestConnectionResult
    {
        $endpoint = rtrim($config['endpoint'] ?? '', '/');
        $url = $endpoint . '/show';

        return ConnectionTester::test(
            'POST',
            $url,
            fn () => Http::withOptions(['verify' => (bool) ($config['verify_ssl'] ?? true)])
                ->asForm()
                ->timeout(10)
                ->post($url, [
                    'data' => json_encode(['op' => 'show', 'path' => ['version']]),
                    'key' => $config['api_key'] ?? '',
                ]),
            'Connected and authenticated successfully',
        );
    }
}
```

### Configuration

New entry in `config/integrations.php`:

```php
'vyos' => [
    'name' => 'VyOS',
    'description' => 'VyOS router providing DHCP, DHCPv6, and IP-MAC resolution.',
    'capabilities' => ['dhcp', 'ip-mac'],
    'fields' => [
        'endpoint' => [
            'type' => 'url',
            'label' => 'API Endpoint',
            'placeholder' => 'https://vyos.local',
            'help' => 'Base URL of your VyOS router HTTP API.',
        ],
        'api_key' => [
            'type' => 'password',
            'label' => 'API Key',
            'help' => 'VyOS HTTP API key for authentication.',
        ],
        'verify_ssl' => [
            'type' => 'toggle',
            'label' => 'Verify SSL',
            'help' => 'Verify the SSL certificate when connecting.',
        ],
        'pool_size' => [
            'type' => 'text',
            'label' => 'DHCP Pool Size',
            'placeholder' => '254',
            'help' => 'Total number of addresses in the DHCP pool for utilisation calculation.',
        ],
    ],
    'validation' => [
        'endpoint' => 'nullable|url|max:500',
        'api_key' => 'nullable|string|max:500',
        'verify_ssl' => 'nullable|string|in:0,1',
        'pool_size' => 'nullable|integer|min:0|max:1000000',
    ],
],
```

### Service Provider Changes

In `IntegrationServiceProvider`:

**`registerSharedSingletons()`** — add:
```php
$this->app->singleton(function (): VyOsClient {
    $dbConfig = $this->getIntegrationDbConfig(Integration::VyOs->value);
    return new VyOsClient(
        endpoint: (string) ($dbConfig['endpoint'] ?? ''),
        apiKey: (string) ($dbConfig['api_key'] ?? ''),
        verifySsl: (bool) ($dbConfig['verify_ssl'] ?? true),
    );
});
```

**`registerCapabilityBindings()`** — add:
```php
(new VyOsBootstrapper)->register($this->app);
```

**`registerIntegrationTesters()`** — add:
```php
$registry->register(Integration::VyOs->value, new VyOsTester);
```

## Testing Strategy

- Unit tests for `VyOsDhcpService` with mocked `VyOsClient` responses
- Unit tests for `VyOsIpMacResolver` with mocked `VyOsClient` responses
- Unit tests for `VyOsClient` with mocked HTTP responses (verify request format)
- Unit test for `VyOsBootstrapper` capability binding (active/inactive states)
- Unit test for `VyOsTester` connection test (success/failure)
- Integration test for config registration (verify VyOS appears in integration list)
- Feature test for DHCP controller with VyOS as active provider

## Error Handling

- `VyOsClient` throws on HTTP errors or non-success API responses
- `VyOsDhcpService` catches `Throwable` and logs warnings for failed API calls (same pattern as OPNsense), returns empty collections for failed endpoint calls
- `VyOsBootstrapper` catches `Throwable` in `isActive()` and falls back to null implementations
- `VyOsTester` delegates error handling to `ConnectionTester::test()` which catches all throwables

## Future Expansion

The `VyOsClient` is designed as a general-purpose VyOS API wrapper. Future capabilities (firewall, DNS forwarding, static routes, VPN) can add new service classes that depend on the same shared singleton without any client changes.
