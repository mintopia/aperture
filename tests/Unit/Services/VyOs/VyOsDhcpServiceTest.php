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

    /**
     * @param  list<string>  $columns
     * @param  list<list<string>>  $dataRows
     */
    private function buildTextTable(array $columns, array $dataRows): string
    {
        $widths = array_map('strlen', $columns);

        foreach ($dataRows as $row) {
            foreach ($row as $i => $value) {
                $widths[$i] = max($widths[$i], strlen($value));
            }
        }

        $lines = [];
        $lines[] = implode('  ', array_map(fn (string $col, int $w): string => str_pad($col, $w), $columns, $widths));
        $lines[] = implode('  ', array_map(fn (int $w): string => str_repeat('-', $w), $widths));

        foreach ($dataRows as $row) {
            $lines[] = implode('  ', array_map(fn (string $val, int $w): string => str_pad($val, $w), $row, $widths));
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  list<array{ip: string, mac?: string, hostname?: string, state?: string, start?: string, expires?: string, remaining?: string, pool?: string, origin?: string}>  $rows
     */
    private function dhcpv4LeaseText(array $rows = []): string
    {
        $columns = ['IP Address', 'MAC address', 'State', 'Lease start', 'Lease expiration', 'Remaining', 'Pool', 'Hostname', 'Origin'];
        $dataRows = [];

        foreach ($rows as $row) {
            $dataRows[] = [
                $row['ip'],
                $row['mac'] ?? 'aa:bb:cc:dd:ee:ff',
                $row['state'] ?? 'active',
                $row['start'] ?? '2026-05-31 20:00:00+00:00',
                $row['expires'] ?? '2026-06-01 20:00:00+00:00',
                $row['remaining'] ?? '8:00:00',
                $row['pool'] ?? 'pool',
                $row['hostname'] ?? '',
                $row['origin'] ?? 'local',
            ];
        }

        return $this->buildTextTable($columns, $dataRows);
    }

    /**
     * @param  list<array{ip: string, mac?: string, hostname?: string, state?: string, last_comm?: string, expires?: string, remaining?: string, pool?: string, type?: string, duid?: string}>  $rows
     */
    private function dhcpv6LeaseText(array $rows = []): string
    {
        $columns = ['IPv6 address', 'MAC address', 'State', 'Last communication', 'Lease expiration', 'Remaining', 'Pool', 'Hostname', 'Type', 'DUID'];
        $dataRows = [];

        foreach ($rows as $row) {
            $dataRows[] = [
                $row['ip'],
                $row['mac'] ?? 'bc:24:11:78:82:5d',
                $row['state'] ?? 'active',
                $row['last_comm'] ?? '2026-06-01 11:40:10+00:00',
                $row['expires'] ?? '2026-06-01 13:40:10+00:00',
                $row['remaining'] ?? '1:35:02',
                $row['pool'] ?? 'pool',
                $row['hostname'] ?? '',
                $row['type'] ?? 'IA_NA',
                $row['duid'] ?? '00:01:00:01:30:e2:f6:78:bc:24:11:78:82:5d',
            ];
        }

        return $this->buildTextTable($columns, $dataRows);
    }

    private function stubEmptyV4Leases(): void
    {
        $this->client->shouldReceive('showText')
            ->with(['dhcp', 'server', 'leases'])
            ->once()
            ->andReturn('');
    }

    private function stubEmptyV6Leases(): void
    {
        $this->client->shouldReceive('showText')
            ->with(['dhcpv6', 'server', 'leases'])
            ->once()
            ->andReturn('');
    }

    public function test_get_leases_returns_dhcpv4_leases(): void
    {
        $this->client->shouldReceive('showText')
            ->with(['dhcp', 'server', 'leases'])
            ->once()
            ->andReturn($this->dhcpv4LeaseText([
                ['ip' => '192.168.1.100', 'mac' => 'aa:bb:cc:dd:ee:ff', 'hostname' => 'workstation1', 'expires' => '2026-05-31 12:00:00+00:00'],
                ['ip' => '192.168.1.101', 'mac' => '11:22:33:44:55:66', 'hostname' => 'workstation2', 'expires' => '2026-05-31 13:00:00+00:00'],
            ]));

        $this->stubEmptyV6Leases();

        $service = $this->createService();
        $leases = $service->getLeases();

        $this->assertCount(2, $leases);
        $this->assertSame('192.168.1.100', $leases[0]->ip);
        $this->assertSame('aa:bb:cc:dd:ee:ff', $leases[0]->mac);
        $this->assertSame('workstation1', $leases[0]->hostname);
        $this->assertSame('2026-05-31 12:00:00+00:00', $leases[0]->expires);
    }

    public function test_get_leases_returns_dhcpv6_leases_with_mac_and_hostname(): void
    {
        $this->stubEmptyV4Leases();

        $this->client->shouldReceive('showText')
            ->with(['dhcpv6', 'server', 'leases'])
            ->once()
            ->andReturn($this->dhcpv6LeaseText([
                ['ip' => '2001:db8::100', 'mac' => 'aa:bb:cc:dd:ee:ff', 'hostname' => 'server1', 'expires' => '2026-05-31 12:00:00+00:00'],
            ]));

        $service = $this->createService();
        $leases = $service->getLeases();

        $this->assertCount(1, $leases);
        $this->assertSame('2001:db8::100', $leases[0]->ip);
        $this->assertSame('aa:bb:cc:dd:ee:ff', $leases[0]->mac);
        $this->assertSame('server1', $leases[0]->hostname);
        $this->assertSame('2026-05-31 12:00:00+00:00', $leases[0]->expires);
    }

    public function test_get_leases_merges_dhcpv4_and_dhcpv6(): void
    {
        $this->client->shouldReceive('showText')
            ->with(['dhcp', 'server', 'leases'])
            ->once()
            ->andReturn($this->dhcpv4LeaseText([
                ['ip' => '192.168.1.100', 'hostname' => 'v4host'],
            ]));

        $this->client->shouldReceive('showText')
            ->with(['dhcpv6', 'server', 'leases'])
            ->once()
            ->andReturn($this->dhcpv6LeaseText([
                ['ip' => '2001:db8::1', 'hostname' => 'v6host'],
            ]));

        $service = $this->createService();
        $leases = $service->getLeases();

        $this->assertCount(2, $leases);
        $this->assertSame('192.168.1.100', $leases[0]->ip);
        $this->assertSame('2001:db8::1', $leases[1]->ip);
    }

    public function test_get_leases_returns_empty_collection_when_no_leases(): void
    {
        $this->stubEmptyV4Leases();
        $this->stubEmptyV6Leases();

        $service = $this->createService();
        $leases = $service->getLeases();

        $this->assertCount(0, $leases);
    }

    public function test_get_leases_handles_api_exception_gracefully(): void
    {
        $this->client->shouldReceive('showText')
            ->with(['dhcp', 'server', 'leases'])
            ->once()
            ->andThrow(new RuntimeException('Connection refused'));

        $this->stubEmptyV6Leases();

        $service = $this->createService();
        $leases = $service->getLeases();

        $this->assertCount(0, $leases);
    }

    public function test_get_lease_returns_matching_lease(): void
    {
        $this->client->shouldReceive('showText')
            ->with(['dhcp', 'server', 'leases'])
            ->once()
            ->andReturn($this->dhcpv4LeaseText([
                ['ip' => '192.168.1.100', 'hostname' => 'target'],
                ['ip' => '192.168.1.101', 'hostname' => 'other'],
            ]));

        $this->stubEmptyV6Leases();

        $service = $this->createService();
        $lease = $service->getLease('192.168.1.100');

        $this->assertNotNull($lease);
        $this->assertSame('192.168.1.100', $lease->ip);
        $this->assertSame('target', $lease->hostname);
    }

    public function test_get_lease_returns_null_when_not_found(): void
    {
        $this->stubEmptyV4Leases();
        $this->stubEmptyV6Leases();

        $service = $this->createService();
        $lease = $service->getLease('10.99.99.99');

        $this->assertNull($lease);
    }

    public function test_get_lease_matches_ipv6_address_case_insensitively(): void
    {
        $this->stubEmptyV4Leases();

        $this->client->shouldReceive('showText')
            ->with(['dhcpv6', 'server', 'leases'])
            ->once()
            ->andReturn($this->dhcpv6LeaseText([
                ['ip' => '2001:DB8::100', 'hostname' => 'v6host'],
            ]));

        $service = $this->createService();
        $lease = $service->getLease('2001:db8::100');

        $this->assertNotNull($lease);
        $this->assertSame('2001:db8::100', $lease->ip);
        $this->assertSame('v6host', $lease->hostname);
    }

    public function test_get_ranges_returns_dhcpv4_ranges(): void
    {
        $this->client->shouldReceive('retrieve')
            ->with(['service', 'dhcp-server', 'shared-network-name'])
            ->once()
            ->andReturn([
                'shared-network-name' => [
                    'MY_NETWORK' => [
                        'subnet' => [
                            '192.168.1.0/24' => [
                                'range' => [
                                    'POOL1' => [
                                        'start' => '192.168.1.100',
                                        'stop' => '192.168.1.200',
                                    ],
                                ],
                                'option' => ['default-router' => '192.168.1.1'],
                            ],
                        ],
                    ],
                ],
            ]);

        $this->client->shouldReceive('retrieve')
            ->with(['service', 'dhcpv6-server', 'shared-network-name'])
            ->once()
            ->andReturn([]);

        $this->client->shouldReceive('showText')
            ->with(['dhcp', 'server', 'leases'])
            ->once()
            ->andReturn($this->dhcpv4LeaseText([
                ['ip' => '192.168.1.150', 'hostname' => 'device1'],
            ]));

        $this->stubEmptyV6Leases();

        $service = $this->createService();
        $ranges = $service->getRanges();

        $this->assertCount(1, $ranges);
        $this->assertSame('ipv4', $ranges[0]->type);
        $this->assertSame('192.168.1.0/24', $ranges[0]->subnet);
        $this->assertSame('192.168.1.100', $ranges[0]->rangeFrom);
        $this->assertSame('192.168.1.200', $ranges[0]->rangeTo);
        $this->assertSame('192.168.1.1', $ranges[0]->gateway);
        $this->assertSame('MY_NETWORK', $ranges[0]->description);
        $this->assertSame('101', $ranges[0]->totalAddresses);
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
                'shared-network-name' => [
                    'MY_V6_NETWORK' => [
                        'subnet' => [
                            '2001:db8::/64' => [
                                'range' => [
                                    'clients' => [
                                        'start' => '2001:db8::100',
                                        'stop' => '2001:db8::200',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]);

        $this->client->shouldReceive('showText')
            ->with(['dhcp', 'server', 'leases'])
            ->once()
            ->andReturn('');

        $this->stubEmptyV6Leases();

        $service = $this->createService();
        $ranges = $service->getRanges();

        $this->assertCount(1, $ranges);
        $this->assertSame('ipv6', $ranges[0]->type);
        $this->assertSame('2001:db8::/64', $ranges[0]->subnet);
        $this->assertSame('2001:db8::100', $ranges[0]->rangeFrom);
        $this->assertSame('2001:db8::200', $ranges[0]->rangeTo);
        $this->assertSame('MY_V6_NETWORK', $ranges[0]->description);
    }

    public function test_get_ranges_normalizes_ipv6_prefix_to_lowercase(): void
    {
        $this->client->shouldReceive('retrieve')
            ->with(['service', 'dhcp-server', 'shared-network-name'])
            ->once()
            ->andReturn([]);

        $this->client->shouldReceive('retrieve')
            ->with(['service', 'dhcpv6-server', 'shared-network-name'])
            ->once()
            ->andReturn([
                'shared-network-name' => [
                    'MY_V6_NETWORK' => [
                        'subnet' => [
                            '2A0F:85C1:D91:2100::/64' => [
                                'range' => [
                                    'clients' => [
                                        'start' => '2A0F:85C1:D91:2100::100',
                                        'stop' => '2A0F:85C1:D91:2100::200',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]);

        $this->client->shouldReceive('showText')
            ->with(['dhcp', 'server', 'leases'])
            ->once()
            ->andReturn('');

        $this->stubEmptyV6Leases();

        $service = $this->createService();
        $ranges = $service->getRanges();

        $this->assertCount(1, $ranges);
        $this->assertSame('ipv6', $ranges[0]->type);
        $this->assertSame('2a0f:85c1:d91:2100::/64', $ranges[0]->prefix);
    }

    public function test_get_ranges_returns_multiple_ranges_from_multiple_subnets(): void
    {
        $this->client->shouldReceive('retrieve')
            ->with(['service', 'dhcp-server', 'shared-network-name'])
            ->once()
            ->andReturn([
                'shared-network-name' => [
                    'NET_A' => [
                        'subnet' => [
                            '10.0.0.0/24' => [
                                'range' => [
                                    'POOL1' => ['start' => '10.0.0.10', 'stop' => '10.0.0.50'],
                                    'POOL2' => ['start' => '10.0.0.100', 'stop' => '10.0.0.200'],
                                ],
                                'option' => ['default-router' => '10.0.0.1'],
                            ],
                        ],
                    ],
                ],
            ]);

        $this->client->shouldReceive('retrieve')
            ->with(['service', 'dhcpv6-server', 'shared-network-name'])
            ->once()
            ->andReturn([]);

        $this->stubEmptyV4Leases();
        $this->stubEmptyV6Leases();

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
        $this->client->shouldReceive('showText')
            ->with(['dhcp', 'server', 'leases'])
            ->once()
            ->andReturn($this->dhcpv4LeaseText([
                ['ip' => '192.168.1.100', 'hostname' => 'a'],
                ['ip' => '192.168.1.101', 'hostname' => 'b'],
            ]));

        $this->stubEmptyV6Leases();

        $service = $this->createService(254);
        $pool = $service->getPoolStatus();

        $this->assertSame(254, $pool->total);
        $this->assertSame(2, $pool->used);
        $this->assertSame(252, $pool->available);
        $this->assertEqualsWithDelta(2 / 254, $pool->utilisation, 0.001);
    }

    public function test_get_pool_status_handles_zero_pool_size(): void
    {
        $this->stubEmptyV4Leases();
        $this->stubEmptyV6Leases();

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
                'shared-network-name' => [
                    'NET' => [
                        'subnet' => [
                            '10.0.0.0/24' => [
                                'option' => ['default-router' => '10.0.0.1'],
                            ],
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
        $this->client->shouldReceive('showText')
            ->with(['dhcp', 'server', 'leases'])
            ->once()
            ->andReturn($this->dhcpv4LeaseText([
                ['ip' => '192.168.1.1', 'hostname' => 'host'],
            ]));

        $this->client->shouldReceive('showText')
            ->with(['dhcpv6', 'server', 'leases'])
            ->once()
            ->andThrow(new RuntimeException('DHCPv6 connection refused'));

        $service = $this->createService();
        $leases = $service->getLeases();

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
                'shared-network-name' => [
                    'NET' => [
                        'subnet' => [
                            '10.0.0.0/24' => [
                                'range' => 'not-an-array',
                                'option' => ['default-router' => '10.0.0.1'],
                            ],
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
                'shared-network-name' => [
                    'NET' => [
                        'subnet' => [
                            '10.0.0.0/24' => [
                                'range' => [
                                    'POOL1' => ['only-start' => '10.0.0.10'],
                                ],
                                'option' => ['default-router' => '10.0.0.1'],
                            ],
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

    public function test_get_ranges_enrichment_with_ipv6_leases_in_range(): void
    {
        $this->client->shouldReceive('retrieve')
            ->with(['service', 'dhcp-server', 'shared-network-name'])
            ->once()
            ->andReturn([]);

        $this->client->shouldReceive('retrieve')
            ->with(['service', 'dhcpv6-server', 'shared-network-name'])
            ->once()
            ->andReturn([
                'shared-network-name' => [
                    'V6NET' => [
                        'subnet' => [
                            '2001:db8::/64' => [
                                'range' => [
                                    'clients' => [
                                        'start' => '2001:db8::100',
                                        'stop' => '2001:db8::200',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]);

        $this->stubEmptyV4Leases();

        $this->client->shouldReceive('showText')
            ->with(['dhcpv6', 'server', 'leases'])
            ->once()
            ->andReturn($this->dhcpv6LeaseText([
                ['ip' => '2001:db8::150', 'hostname' => 'in-range'],
                ['ip' => '2001:db8::300', 'hostname' => 'out-of-range'],
            ]));

        $service = $this->createService();
        $ranges = $service->getRanges();

        $this->assertCount(1, $ranges);
        $this->assertSame(1, $ranges[0]->usedAddresses);
    }

    public function test_get_ranges_ipv6_without_range_key_is_skipped(): void
    {
        $this->client->shouldReceive('retrieve')
            ->with(['service', 'dhcp-server', 'shared-network-name'])
            ->once()
            ->andReturn([]);

        $this->client->shouldReceive('retrieve')
            ->with(['service', 'dhcpv6-server', 'shared-network-name'])
            ->once()
            ->andReturn([
                'shared-network-name' => [
                    'V6NET' => [
                        'subnet' => [
                            '2001:db8::/64' => [
                                'name-server' => '2001:db8::1',
                            ],
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
                'shared-network-name' => [
                    'VALID_NET' => [
                        'subnet' => [
                            '10.0.0.0/24' => [
                                'range' => [
                                    'POOL1' => ['start' => '10.0.0.10', 'stop' => '10.0.0.50'],
                                ],
                                'option' => ['default-router' => '10.0.0.1'],
                            ],
                        ],
                    ],
                    'INVALID_STRING_ENTRY' => 'not-an-array',
                    'NO_SUBNET_ENTRY' => ['description' => 'missing subnet key'],
                ],
            ]);

        $this->client->shouldReceive('retrieve')
            ->with(['service', 'dhcpv6-server', 'shared-network-name'])
            ->once()
            ->andReturn([]);

        $this->stubEmptyV4Leases();
        $this->stubEmptyV6Leases();

        $service = $this->createService();
        $ranges = $service->getRanges();

        $this->assertCount(1, $ranges);
        $this->assertSame('10.0.0.10', $ranges[0]->rangeFrom);
    }

    public function test_get_ranges_skips_non_array_subnet_config(): void
    {
        $this->client->shouldReceive('retrieve')
            ->with(['service', 'dhcp-server', 'shared-network-name'])
            ->once()
            ->andReturn([
                'shared-network-name' => [
                    'NET' => [
                        'subnet' => [
                            '10.0.0.0/24' => [
                                'range' => [
                                    'POOL1' => ['start' => '10.0.0.10', 'stop' => '10.0.0.50'],
                                ],
                                'option' => ['default-router' => '10.0.0.1'],
                            ],
                            '10.1.0.0/24' => 'not-an-array-config',
                        ],
                    ],
                ],
            ]);

        $this->client->shouldReceive('retrieve')
            ->with(['service', 'dhcpv6-server', 'shared-network-name'])
            ->once()
            ->andReturn([]);

        $this->stubEmptyV4Leases();
        $this->stubEmptyV6Leases();

        $service = $this->createService();
        $ranges = $service->getRanges();

        $this->assertCount(1, $ranges);
        $this->assertSame('10.0.0.10', $ranges[0]->rangeFrom);
    }

    public function test_get_leases_handles_empty_text_response(): void
    {
        $this->stubEmptyV4Leases();
        $this->stubEmptyV6Leases();

        $service = $this->createService();
        $leases = $service->getLeases();

        $this->assertCount(0, $leases);
    }

    public function test_get_leases_handles_header_only_text_response(): void
    {
        $this->client->shouldReceive('showText')
            ->with(['dhcp', 'server', 'leases'])
            ->once()
            ->andReturn($this->dhcpv4LeaseText([]));

        $this->client->shouldReceive('showText')
            ->with(['dhcpv6', 'server', 'leases'])
            ->once()
            ->andReturn($this->dhcpv6LeaseText([]));

        $service = $this->createService();
        $leases = $service->getLeases();

        $this->assertCount(0, $leases);
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
                'shared-network-name' => [
                    'V6NET' => [
                        'subnet' => [
                            '2001:db8::/64' => [
                                'range' => [
                                    'clients' => [
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

    public function test_get_ranges_works_without_shared_network_name_wrapper(): void
    {
        $this->client->shouldReceive('retrieve')
            ->with(['service', 'dhcp-server', 'shared-network-name'])
            ->once()
            ->andReturn([
                'MY_NETWORK' => [
                    'subnet' => [
                        '10.0.0.0/24' => [
                            'range' => [
                                'POOL1' => ['start' => '10.0.0.10', 'stop' => '10.0.0.50'],
                            ],
                            'option' => ['default-router' => '10.0.0.1'],
                        ],
                    ],
                ],
            ]);

        $this->client->shouldReceive('retrieve')
            ->with(['service', 'dhcpv6-server', 'shared-network-name'])
            ->once()
            ->andReturn([]);

        $this->stubEmptyV4Leases();
        $this->stubEmptyV6Leases();

        $service = $this->createService();
        $ranges = $service->getRanges();

        $this->assertCount(1, $ranges);
        $this->assertSame('10.0.0.10', $ranges[0]->rangeFrom);
    }
}
