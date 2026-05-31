# VyOS DHCP/DHCPv6 + IP-MAC Integration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add VyOS as a new integration providing DHCP, DHCPv6, and IP-MAC resolution via the VyOS HTTP API.

**Architecture:** Follows the existing capability-driven integration pattern. A shared `VyOsClient` HTTP wrapper communicates with VyOS's POST-based API. `VyOsDhcpService` implements `DhcpInterface` for lease/range data, `VyOsIpMacResolver` implements `IpMacResolverInterface` for ARP/neighbor data. A `VyOsBootstrapper` conditionally binds these based on capability assignments.

**Tech Stack:** PHP 8.3, Laravel, Guzzle (mock handler for tests), PHPUnit, Mockery

**Spec:** `docs/superpowers/specs/2026-05-31-vyos-dhcp-integration-design.md`

---

## File Map

### New Files

| File | Responsibility |
|------|---------------|
| `app/Services/VyOs/VyOsClient.php` | Shared HTTP client — POST-based form-encoded requests to VyOS `/retrieve` and `/show` endpoints |
| `app/Services/VyOs/VyOsDhcpService.php` | Implements `DhcpInterface` — fetches leases (DHCPv4+v6), ranges (from config), pool status |
| `app/Services/VyOs/VyOsIpMacResolver.php` | Implements `IpMacResolverInterface` — fetches ARP table + IPv6 neighbors, deduplicates |
| `app/Integration/VyOsBootstrapper.php` | Implements `IntegrationBootstrapper` — registers DHCP + IP-MAC capability bindings |
| `app/Services/Integration/VyOsTester.php` | Implements `TestableIntegration` — tests VyOS API connectivity |
| `tests/Unit/Services/VyOs/VyOsClientTest.php` | Tests for VyOsClient request formatting and error handling |
| `tests/Unit/Services/VyOs/VyOsDhcpServiceTest.php` | Tests for DHCP lease, range, and pool status parsing |
| `tests/Unit/Services/VyOs/VyOsIpMacResolverTest.php` | Tests for ARP/neighbor merging and deduplication |
| `tests/Unit/Integration/VyOsBootstrapperTest.php` | Tests for bootstrapper interface implementation |
| `tests/Unit/Services/Integration/VyOsTesterTest.php` | Tests for connection tester success/failure paths |

### Modified Files

| File | Change |
|------|--------|
| `app/Enums/Integration.php` | Add `case VyOs = 'vyos'` |
| `config/integrations.php` | Add `'vyos'` configuration block |
| `app/Providers/IntegrationServiceProvider.php` | Register VyOsClient singleton, VyOsBootstrapper, VyOsTester |

---

### Task 1: Add VyOS to Integration Enum and Config

**Files:**
- Modify: `app/Enums/Integration.php`
- Modify: `config/integrations.php`
- Modify: `tests/Unit/Config/IntegrationConfigTest.php` (if exists, to verify)

- [ ] **Step 1: Add `VyOs` case to the Integration enum**

In `app/Enums/Integration.php`, add a new case after `Seatpicker`:

```php
case VyOs = 'vyos';
```

The full enum should look like:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum Integration: string
{
    case OpnSense = 'opnsense';
    case PiHole = 'pihole';
    case LibreNms = 'librenms';
    case Borealis = 'borealis';
    case Prometheus = 'prometheus';
    case Seatpicker = 'seatpicker';
    case VyOs = 'vyos';
}
```

- [ ] **Step 2: Add VyOS configuration block to `config/integrations.php`**

Add this block after the `'prometheus'` entry (before the closing `];`):

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

- [ ] **Step 3: Run existing tests to verify nothing is broken**

Run: `php artisan test --filter=IntegrationConfig`
Expected: All existing tests pass (or if no config tests exist, run `php artisan test --filter=Integration` to check nothing breaks)

- [ ] **Step 4: Commit**

```bash
git add app/Enums/Integration.php config/integrations.php
git commit -m "feat(vyos): add VyOs enum case and integration config"
```

---

### Task 2: VyOsClient — Write Tests

**Files:**
- Create: `tests/Unit/Services/VyOs/VyOsClientTest.php`

- [ ] **Step 1: Create the test file with all test cases**

Create `tests/Unit/Services/VyOs/VyOsClientTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services\VyOs;

use App\Services\VyOs\VyOsClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class VyOsClientTest extends TestCase
{
    private VyOsClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = new VyOsClient(
            endpoint: 'https://vyos.local',
            apiKey: 'test-api-key',
        );
    }

    public function test_retrieve_sends_post_with_correct_format(): void
    {
        Http::fake([
            'vyos.local/retrieve' => Http::response([
                'success' => true,
                'data' => ['some' => 'config'],
                'error' => null,
            ]),
        ]);

        $result = $this->client->retrieve(['service', 'dhcp-server']);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://vyos.local/retrieve'
                && $request->method() === 'POST'
                && $request->data()['key'] === 'test-api-key'
                && json_decode($request->data()['data'], true) === [
                    'op' => 'showConfig',
                    'path' => ['service', 'dhcp-server'],
                ];
        });

        $this->assertSame(['some' => 'config'], $result);
    }

    public function test_show_sends_post_with_correct_format(): void
    {
        Http::fake([
            'vyos.local/show' => Http::response([
                'success' => true,
                'data' => ['version' => '1.4.0'],
                'error' => null,
            ]),
        ]);

        $result = $this->client->show(['version']);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://vyos.local/show'
                && $request->method() === 'POST'
                && $request->data()['key'] === 'test-api-key'
                && json_decode($request->data()['data'], true) === [
                    'op' => 'show',
                    'path' => ['version'],
                ];
        });

        $this->assertSame(['version' => '1.4.0'], $result);
    }

    public function test_retrieve_trims_trailing_slash_from_endpoint(): void
    {
        $client = new VyOsClient(
            endpoint: 'https://vyos.local/',
            apiKey: 'key',
        );

        Http::fake([
            'vyos.local/retrieve' => Http::response([
                'success' => true,
                'data' => [],
                'error' => null,
            ]),
        ]);

        $client->retrieve(['test']);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://vyos.local/retrieve');
    }

    public function test_retrieve_throws_on_non_success_response(): void
    {
        Http::fake([
            'vyos.local/retrieve' => Http::response([
                'success' => false,
                'data' => null,
                'error' => 'Invalid path',
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid path');

        $this->client->retrieve(['invalid', 'path']);
    }

    public function test_show_throws_on_non_success_response(): void
    {
        Http::fake([
            'vyos.local/show' => Http::response([
                'success' => false,
                'data' => null,
                'error' => 'Command failed',
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Command failed');

        $this->client->show(['bad', 'command']);
    }

    public function test_retrieve_throws_on_http_error(): void
    {
        Http::fake([
            'vyos.local/retrieve' => Http::response('Unauthorized', 401),
        ]);

        $this->expectException(RuntimeException::class);

        $this->client->retrieve(['service']);
    }

    public function test_show_throws_on_http_error(): void
    {
        Http::fake([
            'vyos.local/show' => Http::response('Server Error', 500),
        ]);

        $this->expectException(RuntimeException::class);

        $this->client->show(['version']);
    }

    public function test_retrieve_returns_empty_array_when_data_is_null(): void
    {
        Http::fake([
            'vyos.local/retrieve' => Http::response([
                'success' => true,
                'data' => null,
                'error' => null,
            ]),
        ]);

        $result = $this->client->retrieve(['service', 'nonexistent']);

        $this->assertSame([], $result);
    }

    public function test_verify_ssl_is_passed_to_http_client(): void
    {
        $client = new VyOsClient(
            endpoint: 'https://vyos.local',
            apiKey: 'key',
            verifySsl: false,
        );

        Http::fake([
            'vyos.local/show' => Http::response([
                'success' => true,
                'data' => [],
                'error' => null,
            ]),
        ]);

        $client->show(['version']);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://vyos.local/show');
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=VyOsClientTest`
Expected: FAIL — class `App\Services\VyOs\VyOsClient` not found

- [ ] **Step 3: Commit the failing tests**

```bash
git add tests/Unit/Services/VyOs/VyOsClientTest.php
git commit -m "test(vyos): add failing VyOsClient tests"
```

---

### Task 3: VyOsClient — Implementation

**Files:**
- Create: `app/Services/VyOs/VyOsClient.php`

- [ ] **Step 1: Create the VyOsClient class**

Create `app/Services/VyOs/VyOsClient.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\VyOs;

use Illuminate\Support\Facades\Http;
use RuntimeException;

final class VyOsClient
{
    public function __construct(
        private string $endpoint,
        private string $apiKey,
        private bool $verifySsl = true,
    ) {
        $this->endpoint = rtrim($this->endpoint, '/');
    }

    /**
     * Retrieve configuration data from VyOS.
     *
     * @param  list<string>  $path
     * @return array<string, mixed>
     */
    public function retrieve(array $path): array
    {
        return $this->request('/retrieve', 'showConfig', $path);
    }

    /**
     * Run an operational show command on VyOS.
     *
     * @param  list<string>  $path
     * @return array<string, mixed>
     */
    public function show(array $path): array
    {
        return $this->request('/show', 'show', $path);
    }

    /**
     * @param  list<string>  $path
     * @return array<string, mixed>
     */
    private function request(string $uri, string $op, array $path): array
    {
        $response = Http::withOptions(['verify' => $this->verifySsl])
            ->asForm()
            ->timeout(15)
            ->post($this->endpoint.$uri, [
                'data' => (string) json_encode(['op' => $op, 'path' => $path]),
                'key' => $this->apiKey,
            ]);

        if ($response->failed()) {
            throw new RuntimeException('VyOS API request failed: HTTP '.$response->status());
        }

        /** @var array{success: bool, data: mixed, error: string|null} $body */
        $body = $response->json();

        if (! ($body['success'] ?? false)) {
            throw new RuntimeException('VyOS API error: '.($body['error'] ?? 'Unknown error'));
        }

        return (array) ($body['data'] ?? []);
    }
}
```

- [ ] **Step 2: Run the tests to verify they pass**

Run: `php artisan test --filter=VyOsClientTest`
Expected: All 9 tests PASS

- [ ] **Step 3: Commit**

```bash
git add app/Services/VyOs/VyOsClient.php
git commit -m "feat(vyos): implement VyOsClient HTTP API wrapper"
```

---

### Task 4: VyOsDhcpService — Write Tests

**Files:**
- Create: `tests/Unit/Services/VyOs/VyOsDhcpServiceTest.php`

- [ ] **Step 1: Create the test file**

Create `tests/Unit/Services/VyOs/VyOsDhcpServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services\VyOs;

use App\Services\VyOs\VyOsClient;
use App\Services\VyOs\VyOsDhcpService;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class VyOsDhcpServiceTest extends TestCase
{
    private VyOsClient&MockInterface $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = Mockery::mock(VyOsClient::class);
    }

    private function createService(int $poolSize = 254): VyOsDhcpService
    {
        return new VyOsDhcpService($this->client, $poolSize);
    }

    public function test_get_leases_returns_dhcpv4_leases(): void
    {
        $this->client->shouldReceive('show')
            ->with(['dhcp', 'server', 'leases'])
            ->once()
            ->andReturn([
                '192.168.1.100' => [
                    'hardware_address' => 'aa:bb:cc:dd:ee:ff',
                    'hostname' => 'workstation1',
                    'expires' => '2026-05-31T12:00:00',
                ],
                '192.168.1.101' => [
                    'hardware_address' => '11:22:33:44:55:66',
                    'hostname' => 'workstation2',
                    'expires' => '2026-05-31T13:00:00',
                ],
            ]);

        $this->client->shouldReceive('show')
            ->with(['dhcpv6', 'server', 'leases'])
            ->once()
            ->andReturn([]);

        $service = $this->createService();
        $leases = $service->getLeases();

        $this->assertCount(2, $leases);
        $this->assertSame('192.168.1.100', $leases[0]->ip);
        $this->assertSame('aa:bb:cc:dd:ee:ff', $leases[0]->mac);
        $this->assertSame('workstation1', $leases[0]->hostname);
        $this->assertSame('2026-05-31T12:00:00', $leases[0]->expires);
    }

    public function test_get_leases_returns_dhcpv6_leases_with_empty_mac(): void
    {
        $this->client->shouldReceive('show')
            ->with(['dhcp', 'server', 'leases'])
            ->once()
            ->andReturn([]);

        $this->client->shouldReceive('show')
            ->with(['dhcpv6', 'server', 'leases'])
            ->once()
            ->andReturn([
                '2001:db8::100' => [
                    'iaid_duid' => '00:01:00:01',
                    'last_communication' => '2026-05-31T11:00:00',
                    'expires' => '2026-05-31T12:00:00',
                    'type' => 'ia-na',
                ],
            ]);

        $service = $this->createService();
        $leases = $service->getLeases();

        $this->assertCount(1, $leases);
        $this->assertSame('2001:db8::100', $leases[0]->ip);
        $this->assertSame('', $leases[0]->mac);
        $this->assertSame('2026-05-31T12:00:00', $leases[0]->expires);
    }

    public function test_get_leases_merges_dhcpv4_and_dhcpv6(): void
    {
        $this->client->shouldReceive('show')
            ->with(['dhcp', 'server', 'leases'])
            ->once()
            ->andReturn([
                '192.168.1.100' => [
                    'hardware_address' => 'aa:bb:cc:dd:ee:ff',
                    'hostname' => 'v4host',
                    'expires' => '2026-05-31T12:00:00',
                ],
            ]);

        $this->client->shouldReceive('show')
            ->with(['dhcpv6', 'server', 'leases'])
            ->once()
            ->andReturn([
                '2001:db8::1' => [
                    'iaid_duid' => '00:01',
                    'expires' => '2026-05-31T13:00:00',
                    'type' => 'ia-na',
                ],
            ]);

        $service = $this->createService();
        $leases = $service->getLeases();

        $this->assertCount(2, $leases);
        $this->assertSame('192.168.1.100', $leases[0]->ip);
        $this->assertSame('2001:db8::1', $leases[1]->ip);
    }

    public function test_get_leases_returns_empty_collection_when_no_leases(): void
    {
        $this->client->shouldReceive('show')
            ->with(['dhcp', 'server', 'leases'])
            ->once()
            ->andReturn([]);

        $this->client->shouldReceive('show')
            ->with(['dhcpv6', 'server', 'leases'])
            ->once()
            ->andReturn([]);

        $service = $this->createService();
        $leases = $service->getLeases();

        $this->assertCount(0, $leases);
    }

    public function test_get_leases_handles_api_exception_gracefully(): void
    {
        $this->client->shouldReceive('show')
            ->with(['dhcp', 'server', 'leases'])
            ->once()
            ->andThrow(new \RuntimeException('Connection refused'));

        $this->client->shouldReceive('show')
            ->with(['dhcpv6', 'server', 'leases'])
            ->once()
            ->andReturn([]);

        $service = $this->createService();
        $leases = $service->getLeases();

        $this->assertCount(0, $leases);
    }

    public function test_get_lease_returns_matching_lease(): void
    {
        $this->client->shouldReceive('show')
            ->with(['dhcp', 'server', 'leases'])
            ->once()
            ->andReturn([
                '192.168.1.100' => [
                    'hardware_address' => 'aa:bb:cc:dd:ee:ff',
                    'hostname' => 'target',
                    'expires' => '2026-05-31T12:00:00',
                ],
                '192.168.1.101' => [
                    'hardware_address' => '11:22:33:44:55:66',
                    'hostname' => 'other',
                    'expires' => '2026-05-31T13:00:00',
                ],
            ]);

        $this->client->shouldReceive('show')
            ->with(['dhcpv6', 'server', 'leases'])
            ->once()
            ->andReturn([]);

        $service = $this->createService();
        $lease = $service->getLease('192.168.1.100');

        $this->assertNotNull($lease);
        $this->assertSame('192.168.1.100', $lease->ip);
        $this->assertSame('target', $lease->hostname);
    }

    public function test_get_lease_returns_null_when_not_found(): void
    {
        $this->client->shouldReceive('show')
            ->with(['dhcp', 'server', 'leases'])
            ->once()
            ->andReturn([]);

        $this->client->shouldReceive('show')
            ->with(['dhcpv6', 'server', 'leases'])
            ->once()
            ->andReturn([]);

        $service = $this->createService();
        $lease = $service->getLease('10.99.99.99');

        $this->assertNull($lease);
    }

    public function test_get_ranges_returns_dhcpv4_ranges(): void
    {
        $this->client->shouldReceive('retrieve')
            ->with(['service', 'dhcp-server', 'shared-network-name'])
            ->once()
            ->andReturn([
                'MY_NETWORK' => [
                    'subnet' => [
                        '192.168.1.0/24' => [
                            'range' => [
                                'POOL1' => [
                                    'start' => '192.168.1.100',
                                    'stop' => '192.168.1.200',
                                ],
                            ],
                            'default-router' => '192.168.1.1',
                        ],
                    ],
                ],
            ]);

        $this->client->shouldReceive('retrieve')
            ->with(['service', 'dhcpv6-server', 'shared-network-name'])
            ->once()
            ->andReturn([]);

        // Leases call for enrichment
        $this->client->shouldReceive('show')
            ->with(['dhcp', 'server', 'leases'])
            ->once()
            ->andReturn([
                '192.168.1.150' => [
                    'hardware_address' => 'aa:bb:cc:dd:ee:ff',
                    'hostname' => 'device1',
                    'expires' => '2026-05-31T12:00:00',
                ],
            ]);

        $this->client->shouldReceive('show')
            ->with(['dhcpv6', 'server', 'leases'])
            ->once()
            ->andReturn([]);

        $service = $this->createService();
        $ranges = $service->getRanges();

        $this->assertCount(1, $ranges);
        $this->assertSame('ipv4', $ranges[0]->type);
        $this->assertSame('192.168.1.0/24', $ranges[0]->subnet);
        $this->assertSame('192.168.1.100', $ranges[0]->rangeFrom);
        $this->assertSame('192.168.1.200', $ranges[0]->rangeTo);
        $this->assertSame('192.168.1.1', $ranges[0]->gateway);
        $this->assertSame('MY_NETWORK', $ranges[0]->description);
        $this->assertSame(101, $ranges[0]->totalAddresses);
        $this->assertSame(1, $ranges[0]->usedAddresses);
    }

    public function test_get_ranges_returns_dhcpv6_ranges(): void
    {
        $this->client->shouldReceive('retrieve')
            ->with(['service', 'dhcp-server', 'shared-network-name'])
            ->once()
            ->andReturn([]);

        $this->client->shouldReceive('retrieve')
            ->with(['service', 'dhcpv6-server', 'shared-network-name'])
            ->once()
            ->andReturn([
                'MY_V6_NETWORK' => [
                    'subnet' => [
                        '2001:db8::/64' => [
                            'address-range' => [
                                'start' => [
                                    '2001:db8::100' => [
                                        'stop' => '2001:db8::200',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]);

        // Leases for enrichment
        $this->client->shouldReceive('show')
            ->with(['dhcp', 'server', 'leases'])
            ->once()
            ->andReturn([]);

        $this->client->shouldReceive('show')
            ->with(['dhcpv6', 'server', 'leases'])
            ->once()
            ->andReturn([]);

        $service = $this->createService();
        $ranges = $service->getRanges();

        $this->assertCount(1, $ranges);
        $this->assertSame('ipv6', $ranges[0]->type);
        $this->assertSame('2001:db8::/64', $ranges[0]->subnet);
        $this->assertSame('2001:db8::100', $ranges[0]->rangeFrom);
        $this->assertSame('2001:db8::200', $ranges[0]->rangeTo);
        $this->assertSame('MY_V6_NETWORK', $ranges[0]->description);
    }

    public function test_get_ranges_returns_multiple_ranges_from_multiple_subnets(): void
    {
        $this->client->shouldReceive('retrieve')
            ->with(['service', 'dhcp-server', 'shared-network-name'])
            ->once()
            ->andReturn([
                'NET_A' => [
                    'subnet' => [
                        '10.0.0.0/24' => [
                            'range' => [
                                'POOL1' => ['start' => '10.0.0.10', 'stop' => '10.0.0.50'],
                                'POOL2' => ['start' => '10.0.0.100', 'stop' => '10.0.0.200'],
                            ],
                            'default-router' => '10.0.0.1',
                        ],
                    ],
                ],
            ]);

        $this->client->shouldReceive('retrieve')
            ->with(['service', 'dhcpv6-server', 'shared-network-name'])
            ->once()
            ->andReturn([]);

        $this->client->shouldReceive('show')
            ->with(['dhcp', 'server', 'leases'])
            ->once()
            ->andReturn([]);

        $this->client->shouldReceive('show')
            ->with(['dhcpv6', 'server', 'leases'])
            ->once()
            ->andReturn([]);

        $service = $this->createService();
        $ranges = $service->getRanges();

        $this->assertCount(2, $ranges);
        $this->assertSame('10.0.0.10', $ranges[0]->rangeFrom);
        $this->assertSame('10.0.0.50', $ranges[0]->rangeTo);
        $this->assertSame('10.0.0.100', $ranges[1]->rangeFrom);
        $this->assertSame('10.0.0.200', $ranges[1]->rangeTo);
    }

    public function test_get_ranges_returns_empty_collection_when_no_config(): void
    {
        $this->client->shouldReceive('retrieve')
            ->with(['service', 'dhcp-server', 'shared-network-name'])
            ->once()
            ->andReturn([]);

        $this->client->shouldReceive('retrieve')
            ->with(['service', 'dhcpv6-server', 'shared-network-name'])
            ->once()
            ->andReturn([]);

        $service = $this->createService();
        $ranges = $service->getRanges();

        $this->assertCount(0, $ranges);
    }

    public function test_get_ranges_handles_api_exception_gracefully(): void
    {
        $this->client->shouldReceive('retrieve')
            ->with(['service', 'dhcp-server', 'shared-network-name'])
            ->once()
            ->andThrow(new \RuntimeException('Connection refused'));

        $this->client->shouldReceive('retrieve')
            ->with(['service', 'dhcpv6-server', 'shared-network-name'])
            ->once()
            ->andReturn([]);

        $service = $this->createService();
        $ranges = $service->getRanges();

        $this->assertCount(0, $ranges);
    }

    public function test_get_pool_status_returns_correct_stats(): void
    {
        $this->client->shouldReceive('show')
            ->with(['dhcp', 'server', 'leases'])
            ->once()
            ->andReturn([
                '192.168.1.100' => [
                    'hardware_address' => 'aa:bb:cc:dd:ee:ff',
                    'hostname' => 'a',
                    'expires' => '2026-05-31T12:00:00',
                ],
                '192.168.1.101' => [
                    'hardware_address' => '11:22:33:44:55:66',
                    'hostname' => 'b',
                    'expires' => '2026-05-31T13:00:00',
                ],
            ]);

        $this->client->shouldReceive('show')
            ->with(['dhcpv6', 'server', 'leases'])
            ->once()
            ->andReturn([]);

        $service = $this->createService(254);
        $pool = $service->getPoolStatus();

        $this->assertSame(254, $pool->total);
        $this->assertSame(2, $pool->used);
        $this->assertSame(252, $pool->available);
        $this->assertEqualsWithDelta(2 / 254, $pool->utilisation, 0.001);
    }

    public function test_get_pool_status_handles_zero_pool_size(): void
    {
        $this->client->shouldReceive('show')
            ->with(['dhcp', 'server', 'leases'])
            ->once()
            ->andReturn([]);

        $this->client->shouldReceive('show')
            ->with(['dhcpv6', 'server', 'leases'])
            ->once()
            ->andReturn([]);

        $service = $this->createService(0);
        $pool = $service->getPoolStatus();

        $this->assertSame(0, $pool->total);
        $this->assertSame(0, $pool->used);
        $this->assertSame(0, $pool->available);
        $this->assertSame(0.0, $pool->utilisation);
    }

    public function test_get_ranges_subnet_without_range_key_is_skipped(): void
    {
        $this->client->shouldReceive('retrieve')
            ->with(['service', 'dhcp-server', 'shared-network-name'])
            ->once()
            ->andReturn([
                'NET' => [
                    'subnet' => [
                        '10.0.0.0/24' => [
                            'default-router' => '10.0.0.1',
                        ],
                    ],
                ],
            ]);

        $this->client->shouldReceive('retrieve')
            ->with(['service', 'dhcpv6-server', 'shared-network-name'])
            ->once()
            ->andReturn([]);

        $service = $this->createService();
        $ranges = $service->getRanges();

        $this->assertCount(0, $ranges);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=VyOsDhcpServiceTest`
Expected: FAIL — class `App\Services\VyOs\VyOsDhcpService` not found

- [ ] **Step 3: Commit the failing tests**

```bash
git add tests/Unit/Services/VyOs/VyOsDhcpServiceTest.php
git commit -m "test(vyos): add failing VyOsDhcpService tests"
```

---

### Task 5: VyOsDhcpService — Implementation

**Files:**
- Create: `app/Services/VyOs/VyOsDhcpService.php`

- [ ] **Step 1: Create the VyOsDhcpService class**

Create `app/Services/VyOs/VyOsDhcpService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\VyOs;

use App\Services\Interfaces\DhcpInterface;
use App\Services\ValueObjects\DhcpLease;
use App\Services\ValueObjects\DhcpPoolStatus;
use App\Services\ValueObjects\DhcpRange;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class VyOsDhcpService implements DhcpInterface
{
    public function __construct(
        private VyOsClient $client,
        private int $poolSize = 0,
    ) {}

    public function getPoolStatus(): DhcpPoolStatus
    {
        $leaseCount = $this->getLeases()->count();

        return new DhcpPoolStatus(
            total: $this->poolSize,
            used: $leaseCount,
            available: max(0, $this->poolSize - $leaseCount),
            utilisation: $this->poolSize > 0 ? round($leaseCount / $this->poolSize, 4) : 0.0,
        );
    }

    /** @return Collection<int, DhcpLease> */
    public function getLeases(): Collection
    {
        $v4Leases = $this->fetchDhcpv4Leases();
        $v6Leases = $this->fetchDhcpv6Leases();

        return $v4Leases->concat($v6Leases)->values();
    }

    public function getLease(string $ipAddress): ?DhcpLease
    {
        return $this->getLeases()->first(fn (DhcpLease $lease): bool => $lease->ip === $ipAddress);
    }

    /** @return Collection<int, DhcpRange> */
    public function getRanges(): Collection
    {
        $ranges = collect();

        $v4Ranges = $this->fetchDhcpv4Ranges();
        $v6Ranges = $this->fetchDhcpv6Ranges();

        $ranges = $ranges->concat($v4Ranges)->concat($v6Ranges);

        if ($ranges->isEmpty()) {
            return $ranges;
        }

        $leases = $this->getLeases();

        return $ranges->map(fn (DhcpRange $range): DhcpRange => $this->enrichRangeWithUsage($range, $leases))->values();
    }

    /** @return Collection<int, DhcpLease> */
    private function fetchDhcpv4Leases(): Collection
    {
        try {
            $data = $this->client->show(['dhcp', 'server', 'leases']);

            return collect($data)->map(fn (array $entry, string $ip): DhcpLease => new DhcpLease(
                ip: $ip,
                mac: (string) ($entry['hardware_address'] ?? ''),
                hostname: (string) ($entry['hostname'] ?? ''),
                expires: (string) ($entry['expires'] ?? ''),
            ))->values();
        } catch (Throwable $e) {
            Log::warning('Failed to fetch VyOS DHCPv4 leases', ['error' => $e->getMessage()]);

            return collect();
        }
    }

    /** @return Collection<int, DhcpLease> */
    private function fetchDhcpv6Leases(): Collection
    {
        try {
            $data = $this->client->show(['dhcpv6', 'server', 'leases']);

            return collect($data)->map(fn (array $entry, string $ip): DhcpLease => new DhcpLease(
                ip: $ip,
                mac: '',
                hostname: '',
                expires: (string) ($entry['expires'] ?? ''),
            ))->values();
        } catch (Throwable $e) {
            Log::warning('Failed to fetch VyOS DHCPv6 leases', ['error' => $e->getMessage()]);

            return collect();
        }
    }

    /** @return Collection<int, DhcpRange> */
    private function fetchDhcpv4Ranges(): Collection
    {
        try {
            $data = $this->client->retrieve(['service', 'dhcp-server', 'shared-network-name']);

            return $this->parseRangesFromConfig($data, 'ipv4');
        } catch (Throwable $e) {
            Log::warning('Failed to fetch VyOS DHCPv4 ranges', ['error' => $e->getMessage()]);

            return collect();
        }
    }

    /** @return Collection<int, DhcpRange> */
    private function fetchDhcpv6Ranges(): Collection
    {
        try {
            $data = $this->client->retrieve(['service', 'dhcpv6-server', 'shared-network-name']);

            return $this->parseRangesFromConfig($data, 'ipv6');
        } catch (Throwable $e) {
            Log::warning('Failed to fetch VyOS DHCPv6 ranges', ['error' => $e->getMessage()]);

            return collect();
        }
    }

    /**
     * @param  array<string, mixed>  $networkData
     * @return Collection<int, DhcpRange>
     */
    private function parseRangesFromConfig(array $networkData, string $type): Collection
    {
        $ranges = collect();

        foreach ($networkData as $networkName => $network) {
            if (! is_array($network) || ! isset($network['subnet'])) {
                continue;
            }

            foreach ($network['subnet'] as $subnetCidr => $subnetConfig) {
                if (! is_array($subnetConfig)) {
                    continue;
                }

                if ($type === 'ipv4') {
                    $this->extractIpv4Ranges($ranges, (string) $networkName, (string) $subnetCidr, $subnetConfig);
                } else {
                    $this->extractIpv6Ranges($ranges, (string) $networkName, (string) $subnetCidr, $subnetConfig);
                }
            }
        }

        return $ranges;
    }

    /**
     * @param  Collection<int, DhcpRange>  $ranges
     * @param  array<string, mixed>  $subnetConfig
     */
    private function extractIpv4Ranges(Collection $ranges, string $networkName, string $subnetCidr, array $subnetConfig): void
    {
        $rangeData = $subnetConfig['range'] ?? [];
        if (! is_array($rangeData)) {
            return;
        }

        foreach ($rangeData as $rangeName => $range) {
            if (! is_array($range) || ! isset($range['start'], $range['stop'])) {
                continue;
            }

            $ranges->push(new DhcpRange(
                interface: $networkName,
                type: 'ipv4',
                subnet: $subnetCidr,
                rangeFrom: (string) $range['start'],
                rangeTo: (string) $range['stop'],
                prefix: null,
                gateway: isset($subnetConfig['default-router']) ? (string) $subnetConfig['default-router'] : null,
                description: $networkName,
            ));
        }
    }

    /**
     * @param  Collection<int, DhcpRange>  $ranges
     * @param  array<string, mixed>  $subnetConfig
     */
    private function extractIpv6Ranges(Collection $ranges, string $networkName, string $subnetCidr, array $subnetConfig): void
    {
        $addressRange = $subnetConfig['address-range'] ?? [];
        if (! is_array($addressRange) || ! isset($addressRange['start'])) {
            return;
        }

        foreach ($addressRange['start'] as $startAddr => $rangeConfig) {
            if (! is_array($rangeConfig) || ! isset($rangeConfig['stop'])) {
                continue;
            }

            $ranges->push(new DhcpRange(
                interface: $networkName,
                type: 'ipv6',
                subnet: $subnetCidr,
                rangeFrom: (string) $startAddr,
                rangeTo: (string) $rangeConfig['stop'],
                prefix: $subnetCidr,
                gateway: null,
                description: $networkName,
            ));
        }
    }

    /**
     * @param  Collection<int, DhcpLease>  $leases
     */
    private function enrichRangeWithUsage(DhcpRange $range, Collection $leases): DhcpRange
    {
        if ($range->rangeFrom === null || $range->rangeTo === null) {
            return $range;
        }

        if ($range->type === 'ipv6') {
            return $this->enrichIpv6RangeWithUsage($range, $leases);
        }

        return $this->enrichIpv4RangeWithUsage($range, $leases);
    }

    /**
     * @param  Collection<int, DhcpLease>  $leases
     */
    private function enrichIpv4RangeWithUsage(DhcpRange $range, Collection $leases): DhcpRange
    {
        $fromLong = ip2long((string) $range->rangeFrom);
        $toLong = ip2long((string) $range->rangeTo);

        if ($fromLong === false || $toLong === false) {
            return $range;
        }

        $totalAddresses = $toLong - $fromLong + 1;

        $usedAddresses = $leases->filter(function (DhcpLease $lease) use ($fromLong, $toLong): bool {
            $leaseIp = ip2long($lease->ip);

            return $leaseIp !== false && $leaseIp >= $fromLong && $leaseIp <= $toLong;
        })->count();

        return new DhcpRange(
            interface: $range->interface,
            type: $range->type,
            subnet: $range->subnet,
            rangeFrom: $range->rangeFrom,
            rangeTo: $range->rangeTo,
            prefix: $range->prefix,
            gateway: $range->gateway,
            description: $range->description,
            totalAddresses: $totalAddresses,
            usedAddresses: $usedAddresses,
            utilisation: $totalAddresses > 0 ? round($usedAddresses / $totalAddresses, 4) : 0.0,
        );
    }

    /**
     * @param  Collection<int, DhcpLease>  $leases
     */
    private function enrichIpv6RangeWithUsage(DhcpRange $range, Collection $leases): DhcpRange
    {
        $fromBin = inet_pton((string) $range->rangeFrom);
        $toBin = inet_pton((string) $range->rangeTo);

        if ($fromBin === false || $toBin === false) {
            return $range;
        }

        $totalAddresses = $this->ipv6Diff($fromBin, $toBin) + 1;

        $usedAddresses = $leases->filter(function (DhcpLease $lease) use ($fromBin, $toBin): bool {
            $leaseBin = inet_pton($lease->ip);

            return $leaseBin !== false && $leaseBin >= $fromBin && $leaseBin <= $toBin;
        })->count();

        return new DhcpRange(
            interface: $range->interface,
            type: $range->type,
            subnet: $range->subnet,
            rangeFrom: $range->rangeFrom,
            rangeTo: $range->rangeTo,
            prefix: $range->prefix,
            gateway: $range->gateway,
            description: $range->description,
            totalAddresses: $totalAddresses,
            usedAddresses: $usedAddresses,
            utilisation: $totalAddresses > 0 ? round($usedAddresses / $totalAddresses, 4) : 0.0,
        );
    }

    private function ipv6Diff(string $fromBin, string $toBin): int
    {
        $result = 0;

        for ($i = 0; $i <= 15; $i++) {
            $diff = ord($toBin[$i]) - ord($fromBin[$i]);
            $result = ($result << 8) + $diff;

            if ($result > PHP_INT_MAX >> 8) {
                return PHP_INT_MAX;
            }
        }

        return max(0, $result);
    }
}
```

- [ ] **Step 2: Run the tests to verify they pass**

Run: `php artisan test --filter=VyOsDhcpServiceTest`
Expected: All 14 tests PASS

- [ ] **Step 3: Commit**

```bash
git add app/Services/VyOs/VyOsDhcpService.php
git commit -m "feat(vyos): implement VyOsDhcpService for DHCP/DHCPv6"
```

---

### Task 6: VyOsIpMacResolver — Write Tests

**Files:**
- Create: `tests/Unit/Services/VyOs/VyOsIpMacResolverTest.php`

- [ ] **Step 1: Create the test file**

Create `tests/Unit/Services/VyOs/VyOsIpMacResolverTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services\VyOs;

use App\Services\VyOs\VyOsClient;
use App\Services\VyOs\VyOsIpMacResolver;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class VyOsIpMacResolverTest extends TestCase
{
    private VyOsClient&MockInterface $client;

    private VyOsIpMacResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = Mockery::mock(VyOsClient::class);
        $this->resolver = new VyOsIpMacResolver($this->client);
    }

    public function test_get_arp_table_returns_ipv4_neighbors(): void
    {
        $this->client->shouldReceive('show')
            ->with(['ip', 'neighbors'])
            ->once()
            ->andReturn([
                [
                    'ip' => '192.168.1.100',
                    'mac' => 'aa:bb:cc:dd:ee:ff',
                    'interface' => 'eth0',
                    'state' => 'reachable',
                ],
                [
                    'ip' => '192.168.1.101',
                    'mac' => '11:22:33:44:55:66',
                    'interface' => 'eth0',
                    'state' => 'stale',
                ],
            ]);

        $this->client->shouldReceive('show')
            ->with(['ipv6', 'neighbors'])
            ->once()
            ->andReturn([]);

        $result = $this->resolver->getArpTable();

        $this->assertCount(2, $result);
        $this->assertSame('192.168.1.100', $result->get(0)->ip);
        $this->assertSame('aa:bb:cc:dd:ee:ff', $result->get(0)->mac);
        $this->assertSame('192.168.1.101', $result->get(1)->ip);
    }

    public function test_get_arp_table_returns_ipv6_neighbors(): void
    {
        $this->client->shouldReceive('show')
            ->with(['ip', 'neighbors'])
            ->once()
            ->andReturn([]);

        $this->client->shouldReceive('show')
            ->with(['ipv6', 'neighbors'])
            ->once()
            ->andReturn([
                [
                    'ip' => 'fe80::1',
                    'mac' => 'aa:bb:cc:dd:ee:03',
                    'interface' => 'eth0',
                    'state' => 'reachable',
                ],
            ]);

        $result = $this->resolver->getArpTable();

        $this->assertCount(1, $result);
        $this->assertSame('fe80::1', $result->first()->ip);
        $this->assertSame('aa:bb:cc:dd:ee:03', $result->first()->mac);
    }

    public function test_get_arp_table_merges_ipv4_and_ipv6(): void
    {
        $this->client->shouldReceive('show')
            ->with(['ip', 'neighbors'])
            ->once()
            ->andReturn([
                ['ip' => '192.168.1.1', 'mac' => 'aa:bb:cc:dd:ee:01', 'interface' => 'eth0', 'state' => 'reachable'],
            ]);

        $this->client->shouldReceive('show')
            ->with(['ipv6', 'neighbors'])
            ->once()
            ->andReturn([
                ['ip' => 'fe80::1', 'mac' => 'aa:bb:cc:dd:ee:02', 'interface' => 'eth0', 'state' => 'reachable'],
            ]);

        $result = $this->resolver->getArpTable();

        $this->assertCount(2, $result);
        $ips = $result->pluck('ip')->all();
        $this->assertContains('192.168.1.1', $ips);
        $this->assertContains('fe80::1', $ips);
    }

    public function test_get_arp_table_deduplicates_by_ip_and_mac(): void
    {
        $this->client->shouldReceive('show')
            ->with(['ip', 'neighbors'])
            ->once()
            ->andReturn([
                ['ip' => '192.168.1.1', 'mac' => 'aa:bb:cc:dd:ee:01', 'interface' => 'eth0', 'state' => 'reachable'],
            ]);

        $this->client->shouldReceive('show')
            ->with(['ipv6', 'neighbors'])
            ->once()
            ->andReturn([
                ['ip' => '192.168.1.1', 'mac' => 'aa:bb:cc:dd:ee:01', 'interface' => 'eth1', 'state' => 'reachable'],
                ['ip' => 'fe80::1', 'mac' => 'aa:bb:cc:dd:ee:02', 'interface' => 'eth0', 'state' => 'reachable'],
            ]);

        $result = $this->resolver->getArpTable();

        $this->assertCount(2, $result);
    }

    public function test_get_arp_table_returns_empty_collection_when_no_data(): void
    {
        $this->client->shouldReceive('show')
            ->with(['ip', 'neighbors'])
            ->once()
            ->andReturn([]);

        $this->client->shouldReceive('show')
            ->with(['ipv6', 'neighbors'])
            ->once()
            ->andReturn([]);

        $result = $this->resolver->getArpTable();

        $this->assertCount(0, $result);
    }

    public function test_get_arp_table_handles_api_exception_gracefully(): void
    {
        $this->client->shouldReceive('show')
            ->with(['ip', 'neighbors'])
            ->once()
            ->andThrow(new \RuntimeException('Connection refused'));

        $this->client->shouldReceive('show')
            ->with(['ipv6', 'neighbors'])
            ->once()
            ->andReturn([
                ['ip' => 'fe80::1', 'mac' => 'aa:bb:cc:dd:ee:01', 'interface' => 'eth0', 'state' => 'reachable'],
            ]);

        $result = $this->resolver->getArpTable();

        $this->assertCount(1, $result);
        $this->assertSame('fe80::1', $result->first()->ip);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=VyOsIpMacResolverTest`
Expected: FAIL — class `App\Services\VyOs\VyOsIpMacResolver` not found

- [ ] **Step 3: Commit the failing tests**

```bash
git add tests/Unit/Services/VyOs/VyOsIpMacResolverTest.php
git commit -m "test(vyos): add failing VyOsIpMacResolver tests"
```

---

### Task 7: VyOsIpMacResolver — Implementation

**Files:**
- Create: `app/Services/VyOs/VyOsIpMacResolver.php`

- [ ] **Step 1: Create the VyOsIpMacResolver class**

Create `app/Services/VyOs/VyOsIpMacResolver.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\VyOs;

use App\Services\Interfaces\IpMacResolverInterface;
use App\Services\ValueObjects\ArpEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class VyOsIpMacResolver implements IpMacResolverInterface
{
    public function __construct(
        private VyOsClient $client,
    ) {}

    /** @return Collection<int, ArpEntry> */
    public function getArpTable(): Collection
    {
        $ipv4 = $this->fetchIpv4Neighbors();
        $ipv6 = $this->fetchIpv6Neighbors();

        return $ipv4->concat($ipv6)
            ->unique(fn (ArpEntry $entry): string => $entry->ip.'|'.$entry->mac)
            ->values();
    }

    /** @return Collection<int, ArpEntry> */
    private function fetchIpv4Neighbors(): Collection
    {
        try {
            $data = $this->client->show(['ip', 'neighbors']);

            return collect($data)->map(fn (array $entry): ArpEntry => new ArpEntry(
                ip: (string) ($entry['ip'] ?? ''),
                mac: (string) ($entry['mac'] ?? ''),
            ))->values();
        } catch (Throwable $e) {
            Log::warning('Failed to fetch VyOS IPv4 neighbors', ['error' => $e->getMessage()]);

            return collect();
        }
    }

    /** @return Collection<int, ArpEntry> */
    private function fetchIpv6Neighbors(): Collection
    {
        try {
            $data = $this->client->show(['ipv6', 'neighbors']);

            return collect($data)->map(fn (array $entry): ArpEntry => new ArpEntry(
                ip: (string) ($entry['ip'] ?? ''),
                mac: (string) ($entry['mac'] ?? ''),
            ))->values();
        } catch (Throwable $e) {
            Log::warning('Failed to fetch VyOS IPv6 neighbors', ['error' => $e->getMessage()]);

            return collect();
        }
    }
}
```

- [ ] **Step 2: Run the tests to verify they pass**

Run: `php artisan test --filter=VyOsIpMacResolverTest`
Expected: All 6 tests PASS

- [ ] **Step 3: Commit**

```bash
git add app/Services/VyOs/VyOsIpMacResolver.php
git commit -m "feat(vyos): implement VyOsIpMacResolver for ARP/neighbor tables"
```

---

### Task 8: VyOsBootstrapper — Write Tests and Implement

**Files:**
- Create: `tests/Unit/Integration/VyOsBootstrapperTest.php`
- Create: `app/Integration/VyOsBootstrapper.php`

- [ ] **Step 1: Create the test file**

Create `tests/Unit/Integration/VyOsBootstrapperTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Integration;

use App\Integration\IntegrationBootstrapper;
use App\Integration\VyOsBootstrapper;
use PHPUnit\Framework\TestCase;

class VyOsBootstrapperTest extends TestCase
{
    public function test_implements_interface(): void
    {
        $this->assertInstanceOf(IntegrationBootstrapper::class, new VyOsBootstrapper);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=VyOsBootstrapperTest`
Expected: FAIL — class `App\Integration\VyOsBootstrapper` not found

- [ ] **Step 3: Create the VyOsBootstrapper class**

Create `app/Integration/VyOsBootstrapper.php`:

```php
<?php

declare(strict_types=1);

namespace App\Integration;

use App\Enums\Capability;
use App\Enums\Integration;
use App\Models\CapabilityAssignment;
use App\Models\IntegrationConfig;
use App\Services\Interfaces\DhcpInterface;
use App\Services\Interfaces\IpMacResolverInterface;
use App\Services\Null\NullDhcpService;
use App\Services\Null\NullIpMacResolver;
use App\Services\VyOs\VyOsClient;
use App\Services\VyOs\VyOsDhcpService;
use App\Services\VyOs\VyOsIpMacResolver;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

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
            return CapabilityAssignment::isActiveProvider(Integration::VyOs->value, $capability);
        } catch (Throwable) {
            return false;
        }
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=VyOsBootstrapperTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add tests/Unit/Integration/VyOsBootstrapperTest.php app/Integration/VyOsBootstrapper.php
git commit -m "feat(vyos): add VyOsBootstrapper for capability registration"
```

---

### Task 9: VyOsTester — Write Tests and Implement

**Files:**
- Create: `tests/Unit/Services/Integration/VyOsTesterTest.php`
- Create: `app/Services/Integration/VyOsTester.php`

- [ ] **Step 1: Create the test file**

Create `tests/Unit/Services/Integration/VyOsTesterTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Integration;

use App\Services\Integration\VyOsTester;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VyOsTesterTest extends TestCase
{
    private VyOsTester $tester;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tester = new VyOsTester;
    }

    public function test_returns_success_on_200_response(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => 'VyOS 1.4.0'], 200)]);

        $result = $this->tester->connect([
            'endpoint' => 'https://vyos.local',
            'api_key' => 'test-key',
        ]);

        $this->assertTrue($result->success);
        $this->assertSame('Connected and authenticated successfully', $result->message);
        $this->assertSame('POST', $result->requestMethod);
        $this->assertStringContainsString('/show', $result->requestUrl);
        $this->assertSame(200, $result->responseStatus);
    }

    public function test_returns_failure_on_401_response(): void
    {
        Http::fake(['*' => Http::response('Unauthorized', 401)]);

        $result = $this->tester->connect([
            'endpoint' => 'https://vyos.local',
            'api_key' => 'bad-key',
        ]);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Connection failed:', $result->message);
        $this->assertNull($result->responseStatus);
    }

    public function test_sends_api_key_in_form_data(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => ''], 200)]);

        $this->tester->connect([
            'endpoint' => 'https://vyos.local',
            'api_key' => 'my-secret-key',
        ]);

        Http::assertSent(function ($request): bool {
            return $request->data()['key'] === 'my-secret-key'
                && json_decode($request->data()['data'], true) === ['op' => 'show', 'path' => ['version']];
        });
    }

    public function test_uses_endpoint_from_config(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => ''], 200)]);

        $this->tester->connect(['endpoint' => 'https://my-vyos.example.com']);

        Http::assertSent(fn ($req): bool => str_contains($req->url(), 'my-vyos.example.com'));
    }

    public function test_trims_trailing_slash_from_endpoint(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => ''], 200)]);

        $this->tester->connect(['endpoint' => 'https://vyos.local/']);

        Http::assertSent(fn ($req): bool => str_contains($req->url(), 'https://vyos.local/show'));
    }

    public function test_returns_failure_on_connection_exception(): void
    {
        Http::fake(['*' => Http::failedConnection()]);

        $result = $this->tester->connect(['endpoint' => 'https://unreachable.local']);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Connection failed:', $result->message);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=VyOsTesterTest`
Expected: FAIL — class `App\Services\Integration\VyOsTester` not found

- [ ] **Step 3: Create the VyOsTester class**

Create `app/Services/Integration/VyOsTester.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Integration;

use App\Services\Interfaces\TestableIntegration;
use App\Services\ValueObjects\TestConnectionResult;
use Illuminate\Support\Facades\Http;

class VyOsTester implements TestableIntegration
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function connect(array $config): TestConnectionResult
    {
        $endpoint = rtrim($config['endpoint'] ?? '', '/');
        $url = $endpoint.'/show';

        return ConnectionTester::test(
            'POST',
            $url,
            fn () => Http::withOptions(['verify' => (bool) ($config['verify_ssl'] ?? true)])
                ->asForm()
                ->timeout(10)
                ->post($url, [
                    'data' => (string) json_encode(['op' => 'show', 'path' => ['version']]),
                    'key' => $config['api_key'] ?? '',
                ]),
            'Connected and authenticated successfully',
        );
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --filter=VyOsTesterTest`
Expected: All 6 tests PASS

- [ ] **Step 5: Commit**

```bash
git add tests/Unit/Services/Integration/VyOsTesterTest.php app/Services/Integration/VyOsTester.php
git commit -m "feat(vyos): add VyOsTester connection tester"
```

---

### Task 10: Wire VyOS into IntegrationServiceProvider

**Files:**
- Modify: `app/Providers/IntegrationServiceProvider.php`

- [ ] **Step 1: Add imports at the top of `IntegrationServiceProvider.php`**

Add these imports to the existing import block:

```php
use App\Integration\VyOsBootstrapper;
use App\Services\Integration\VyOsTester;
use App\Services\VyOs\VyOsClient;
```

- [ ] **Step 2: Register VyOsTester in `registerIntegrationTesters()`**

Add after the Seatpicker tester registration (line 54):

```php
$registry->register(Integration::VyOs->value, new VyOsTester);
```

- [ ] **Step 3: Register VyOsClient singleton in `registerSharedSingletons()`**

Add after the `LibreNmsService` singleton (after line 95):

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

- [ ] **Step 4: Register VyOsBootstrapper in `registerCapabilityBindings()`**

Add after the PiHole bootstrapper (after line 106):

```php
(new VyOsBootstrapper)->register($this->app);
```

- [ ] **Step 5: Run the full test suite to verify nothing is broken**

Run: `php artisan test`
Expected: All tests PASS

- [ ] **Step 6: Commit**

```bash
git add app/Providers/IntegrationServiceProvider.php
git commit -m "feat(vyos): wire VyOS into IntegrationServiceProvider"
```

---

### Task 11: Run Quality Checks

**Files:** None (verification only)

- [ ] **Step 1: Run Laravel Pint**

Run: `./vendor/bin/pint`
Expected: All files formatted correctly (or fixes applied automatically)

- [ ] **Step 2: Run Rector**

Run: `./vendor/bin/rector process --dry-run`
Expected: No changes needed (or apply fixes if suggested)

- [ ] **Step 3: Run PHPStan**

Run: `./vendor/bin/phpstan analyse`
Expected: No errors at level 8

- [ ] **Step 4: Run the full test suite with coverage**

Run: `XDEBUG_MODE=coverage php artisan test --coverage`
Expected: All tests pass, new VyOS code has 100% coverage

- [ ] **Step 5: Fix any issues found and commit**

If Pint/Rector/PHPStan found issues:

```bash
git add -A
git commit -m "fix(vyos): address linting and static analysis issues"
```

- [ ] **Step 6: Run full test suite one final time**

Run: `php artisan test`
Expected: All tests PASS
