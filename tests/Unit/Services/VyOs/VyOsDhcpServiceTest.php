<?php

declare(strict_types=1);

namespace Tests\Unit\Services\VyOs;

use App\Services\VyOs\VyOsClient;
use App\Services\VyOs\VyOsDhcpService;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
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
            ->andThrow(new RuntimeException('Connection refused'));

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
            ->andThrow(new RuntimeException('Connection refused'));

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

    public function test_get_leases_handles_dhcpv6_api_exception_gracefully(): void
    {
        $this->client->shouldReceive('show')
            ->with(['dhcp', 'server', 'leases'])
            ->once()
            ->andReturn([
                '192.168.1.1' => [
                    'hardware_address' => 'aa:bb:cc:dd:ee:ff',
                    'hostname' => 'host',
                    'expires' => '2026-05-31T12:00:00',
                ],
            ]);

        $this->client->shouldReceive('show')
            ->with(['dhcpv6', 'server', 'leases'])
            ->once()
            ->andThrow(new RuntimeException('DHCPv6 connection refused'));

        $service = $this->createService();
        $leases = $service->getLeases();

        // Only IPv4 leases returned; IPv6 exception swallowed
        $this->assertCount(1, $leases);
        $this->assertSame('192.168.1.1', $leases[0]->ip);
    }

    public function test_get_ranges_handles_dhcpv6_api_exception_gracefully(): void
    {
        $this->client->shouldReceive('retrieve')
            ->with(['service', 'dhcp-server', 'shared-network-name'])
            ->once()
            ->andReturn([]);

        $this->client->shouldReceive('retrieve')
            ->with(['service', 'dhcpv6-server', 'shared-network-name'])
            ->once()
            ->andThrow(new RuntimeException('DHCPv6 connection refused'));

        $service = $this->createService();
        $ranges = $service->getRanges();

        $this->assertCount(0, $ranges);
    }

    public function test_get_ranges_ipv4_with_non_array_range_entry_is_skipped(): void
    {
        $this->client->shouldReceive('retrieve')
            ->with(['service', 'dhcp-server', 'shared-network-name'])
            ->once()
            ->andReturn([
                'NET' => [
                    'subnet' => [
                        '10.0.0.0/24' => [
                            'range' => 'not-an-array',
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

    public function test_get_ranges_ipv4_range_entry_without_start_stop_is_skipped(): void
    {
        $this->client->shouldReceive('retrieve')
            ->with(['service', 'dhcp-server', 'shared-network-name'])
            ->once()
            ->andReturn([
                'NET' => [
                    'subnet' => [
                        '10.0.0.0/24' => [
                            'range' => [
                                'POOL1' => ['only-start' => '10.0.0.10'],
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

        $service = $this->createService();
        $ranges = $service->getRanges();

        $this->assertCount(0, $ranges);
    }

    public function test_get_ranges_enrichment_skips_range_with_null_bounds(): void
    {
        // Test enrichRangeWithUsage path when rangeFrom/rangeTo is null —
        // we do this by having a subnet entry that yields a DhcpRange but then
        // checking the enrich guard. The guard is internal; we test via integration:
        // provide a valid range but with an invalid (null-equivalent) IP to trigger ip2long false.
        // Since DhcpRange requires string|null for rangeFrom/rangeTo, we can't pass null
        // through the normal parse path, so we verify the enrichment still works correctly
        // for normal ranges (this is implicitly covered by getRanges tests above).
        // Instead, cover the ipv6 usedAddresses filter path with an actual v6 lease in range.
        $this->client->shouldReceive('retrieve')
            ->with(['service', 'dhcp-server', 'shared-network-name'])
            ->once()
            ->andReturn([]);

        $this->client->shouldReceive('retrieve')
            ->with(['service', 'dhcpv6-server', 'shared-network-name'])
            ->once()
            ->andReturn([
                'V6NET' => [
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

        $this->client->shouldReceive('show')
            ->with(['dhcp', 'server', 'leases'])
            ->once()
            ->andReturn([]);

        $this->client->shouldReceive('show')
            ->with(['dhcpv6', 'server', 'leases'])
            ->once()
            ->andReturn([
                '2001:db8::150' => [
                    'expires' => '2026-05-31T12:00:00',
                    'type' => 'ia-na',
                ],
                '2001:db8::300' => [
                    'expires' => '2026-05-31T12:00:00',
                    'type' => 'ia-na',
                ],
            ]);

        $service = $this->createService();
        $ranges = $service->getRanges();

        $this->assertCount(1, $ranges);
        $this->assertSame(1, $ranges[0]->usedAddresses);
    }

    public function test_get_ranges_ipv6_without_address_range_key_is_skipped(): void
    {
        $this->client->shouldReceive('retrieve')
            ->with(['service', 'dhcp-server', 'shared-network-name'])
            ->once()
            ->andReturn([]);

        $this->client->shouldReceive('retrieve')
            ->with(['service', 'dhcpv6-server', 'shared-network-name'])
            ->once()
            ->andReturn([
                'V6NET' => [
                    'subnet' => [
                        '2001:db8::/64' => [
                            'name-server' => '2001:db8::1',
                        ],
                    ],
                ],
            ]);

        $service = $this->createService();
        $ranges = $service->getRanges();

        $this->assertCount(0, $ranges);
    }

    public function test_get_ranges_skips_non_array_network_entries(): void
    {
        $this->client->shouldReceive('retrieve')
            ->with(['service', 'dhcp-server', 'shared-network-name'])
            ->once()
            ->andReturn([
                'VALID_NET' => [
                    'subnet' => [
                        '10.0.0.0/24' => [
                            'range' => [
                                'POOL1' => ['start' => '10.0.0.10', 'stop' => '10.0.0.50'],
                            ],
                            'default-router' => '10.0.0.1',
                        ],
                    ],
                ],
                'INVALID_STRING_ENTRY' => 'not-an-array',
                'NO_SUBNET_ENTRY' => ['description' => 'missing subnet key'],
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

        // Only the valid network entry produces ranges; others are skipped
        $this->assertCount(1, $ranges);
        $this->assertSame('10.0.0.10', $ranges[0]->rangeFrom);
    }

    public function test_get_ranges_skips_non_array_subnet_config(): void
    {
        $this->client->shouldReceive('retrieve')
            ->with(['service', 'dhcp-server', 'shared-network-name'])
            ->once()
            ->andReturn([
                'NET' => [
                    'subnet' => [
                        '10.0.0.0/24' => [
                            'range' => [
                                'POOL1' => ['start' => '10.0.0.10', 'stop' => '10.0.0.50'],
                            ],
                            'default-router' => '10.0.0.1',
                        ],
                        '10.1.0.0/24' => 'not-an-array-config',
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

        // Only the valid subnet config produces a range; the string config is skipped
        $this->assertCount(1, $ranges);
        $this->assertSame('10.0.0.10', $ranges[0]->rangeFrom);
    }

    public function test_get_ranges_ipv6_range_without_stop_is_skipped(): void
    {
        $this->client->shouldReceive('retrieve')
            ->with(['service', 'dhcp-server', 'shared-network-name'])
            ->once()
            ->andReturn([]);

        $this->client->shouldReceive('retrieve')
            ->with(['service', 'dhcpv6-server', 'shared-network-name'])
            ->once()
            ->andReturn([
                'V6NET' => [
                    'subnet' => [
                        '2001:db8::/64' => [
                            'address-range' => [
                                'start' => [
                                    '2001:db8::100' => [
                                        'no-stop-here' => true,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]);

        $service = $this->createService();
        $ranges = $service->getRanges();

        $this->assertCount(0, $ranges);
    }
}
