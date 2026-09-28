<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Kea;

use App\Services\Interfaces\DhcpInterface;
use App\Services\Kea\KeaClient;
use App\Services\Kea\KeaDhcpService;
use App\Services\ValueObjects\DhcpLease;
use App\Services\ValueObjects\DhcpPoolStatus;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class KeaDhcpServiceTest extends TestCase
{
    private const NOW_TIMESTAMP = 2_000_000_000;

    private KeaDhcpService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::createFromTimestamp(self::NOW_TIMESTAMP));
        $this->service = new KeaDhcpService(new KeaClient(endpoint: 'https://kea.local'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_implements_dhcp_interface(): void
    {
        $this->assertInstanceOf(DhcpInterface::class, $this->service);
    }

    public function test_get_pool_status_returns_zeroed_status_when_kea_reports_result_three(): void
    {
        $this->fakeConfigGet(['result' => 3, 'text' => 'no config']);

        $status = $this->service->getPoolStatus();

        $this->assertInstanceOf(DhcpPoolStatus::class, $status);
        $this->assertSame(0, $status->total);
        $this->assertSame(0, $status->used);
        $this->assertSame(0, $status->available);
        $this->assertSame(0.0, $status->utilisation);
    }

    public function test_get_ranges_returns_empty_collection_when_no_subnets_configured(): void
    {
        $this->fakeConfigGet([
            'result' => 0,
            'arguments' => ['Dhcp4' => ['subnet4' => []]],
        ]);

        $ranges = $this->service->getRanges();

        $this->assertInstanceOf(Collection::class, $ranges);
        $this->assertTrue($ranges->isEmpty());
    }

    public function test_get_ranges_sends_config_get_command(): void
    {
        $this->fakeConfigGet(['result' => 3]);

        $this->service->getRanges();

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return $data['command'] === 'config-get' && ! isset($data['arguments']);
        });
    }

    public function test_dhcp4_missing_from_arguments_returns_empty_collection(): void
    {
        $this->fakeConfigGet([
            'result' => 0,
            'arguments' => ['SomethingElse' => []],
        ]);

        $this->assertTrue($this->service->getRanges()->isEmpty());
    }

    public function test_dhcp4_non_array_returns_empty_collection(): void
    {
        $this->fakeConfigGet([
            'result' => 0,
            'arguments' => ['Dhcp4' => 'not-an-array'],
        ]);

        $this->assertTrue($this->service->getRanges()->isEmpty());
    }

    public function test_ignores_non_array_subnet4_and_shared_networks(): void
    {
        $this->fakeConfigGet([
            'result' => 0,
            'arguments' => [
                'Dhcp4' => [
                    'subnet4' => 'not-an-array',
                    'shared-networks' => 'not-an-array',
                ],
            ],
        ]);

        $this->assertTrue($this->service->getRanges()->isEmpty());
    }

    public function test_parses_range_format_pool_with_and_without_spaces(): void
    {
        $this->fakeConfigGet([
            'result' => 0,
            'arguments' => [
                'Dhcp4' => [
                    'subnet4' => [
                        [
                            'subnet' => '10.0.0.0/24',
                            'interface' => 'eth0',
                            'pools' => [
                                ['pool' => '10.0.0.10-10.0.0.20'],
                                ['pool' => '10.0.0.30 - 10.0.0.40'],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $ranges = $this->service->getRanges()->values()->all();

        $this->assertCount(2, $ranges);
        $this->assertSame('10.0.0.10', $ranges[0]->rangeFrom);
        $this->assertSame('10.0.0.20', $ranges[0]->rangeTo);
        $this->assertSame('eth0', $ranges[0]->interface);
        $this->assertSame('10.0.0.30', $ranges[1]->rangeFrom);
        $this->assertSame('10.0.0.40', $ranges[1]->rangeTo);
    }

    public function test_parses_cidr_format_pools(): void
    {
        $this->fakeConfigGet([
            'result' => 0,
            'arguments' => [
                'Dhcp4' => [
                    'subnet4' => [
                        [
                            'subnet' => '10.0.0.0/24',
                            'interface' => 'eth0',
                            'pools' => [
                                ['pool' => '10.0.0.64/26'],
                                ['pool' => '10.0.0.5/32'],
                                ['pool' => '10.0.0.70/26'],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $ranges = $this->service->getRanges()->values()->all();

        $this->assertCount(3, $ranges);
        $this->assertSame('10.0.0.64', $ranges[0]->rangeFrom);
        $this->assertSame('10.0.0.127', $ranges[0]->rangeTo);
        $this->assertSame('10.0.0.5', $ranges[1]->rangeFrom);
        $this->assertSame('10.0.0.5', $ranges[1]->rangeTo);
        $this->assertSame('10.0.0.64', $ranges[2]->rangeFrom);
        $this->assertSame('10.0.0.127', $ranges[2]->rangeTo);
    }

    public function test_includes_subnets_inside_shared_networks_with_interface_fallback(): void
    {
        $this->fakeConfigGet([
            'result' => 0,
            'arguments' => [
                'Dhcp4' => [
                    'subnet4' => [],
                    'shared-networks' => [
                        [
                            'name' => 'office-network',
                            'subnet4' => [
                                [
                                    'subnet' => '10.1.0.0/24',
                                    'pools' => [['pool' => '10.1.0.10 - 10.1.0.20']],
                                ],
                                [
                                    'subnet' => '10.2.0.0/24',
                                    'interface' => 'eth5',
                                    'pools' => [['pool' => '10.2.0.10 - 10.2.0.20']],
                                ],
                            ],
                        ],
                        [
                            'name' => '',
                            'subnet4' => [
                                [
                                    'subnet' => '10.3.0.0/24',
                                    'pools' => [['pool' => '10.3.0.10 - 10.3.0.20']],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $ranges = $this->service->getRanges()->values()->all();

        $this->assertCount(3, $ranges);
        $this->assertSame('office-network', $ranges[0]->interface);
        $this->assertSame('eth5', $ranges[1]->interface);
        $this->assertSame('', $ranges[2]->interface);
    }

    public function test_label_fallback_order(): void
    {
        $this->fakeConfigGet([
            'result' => 0,
            'arguments' => [
                'Dhcp4' => [
                    'subnet4' => [
                        [
                            'subnet' => '10.0.0.0/24',
                            'user-context' => ['name' => 'subnet-name'],
                            'pools' => [
                                ['pool' => '10.0.0.10 - 10.0.0.20', 'user-context' => ['name' => 'pool-name']],
                                ['pool' => '10.0.0.30 - 10.0.0.40'],
                                ['pool' => '10.0.0.50 - 10.0.0.60', 'user-context' => ['name' => '']],
                            ],
                        ],
                        [
                            'subnet' => '10.1.0.0/24',
                            'user-context' => ['name' => ''],
                            'pools' => [
                                ['pool' => '10.1.0.10 - 10.1.0.20'],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $ranges = $this->service->getRanges()->values()->all();

        $this->assertCount(4, $ranges);
        $this->assertSame('pool-name', $ranges[0]->description);
        $this->assertSame('subnet-name', $ranges[1]->description);
        $this->assertSame('subnet-name', $ranges[2]->description);
        $this->assertSame("10.1.0.0/24 (10.1.0.10\u{2013}10.1.0.20)", $ranges[3]->description);
    }

    public function test_pd_pools_are_ignored(): void
    {
        $this->fakeConfigGet([
            'result' => 0,
            'arguments' => [
                'Dhcp4' => [
                    'subnet4' => [
                        [
                            'subnet' => '10.0.0.0/24',
                            'pools' => [['pool' => '10.0.0.10 - 10.0.0.20']],
                            'pd-pools' => [['prefix' => '2001:db8::', 'prefix-len' => 48, 'delegated-len' => 64]],
                        ],
                    ],
                ],
            ],
        ]);

        $ranges = $this->service->getRanges()->values()->all();

        $this->assertCount(1, $ranges);
        $this->assertSame('10.0.0.10', $ranges[0]->rangeFrom);
        $this->assertSame('10.0.0.20', $ranges[0]->rangeTo);
    }

    public function test_skips_malformed_subnet_and_pool_entries(): void
    {
        $this->fakeConfigGet([
            'result' => 0,
            'arguments' => [
                'Dhcp4' => [
                    'subnet4' => [
                        'not-an-array',
                        ['pools' => [['pool' => '10.0.0.1 - 10.0.0.2']]],
                        ['subnet' => 123, 'pools' => [['pool' => '10.0.0.1 - 10.0.0.2']]],
                        ['subnet' => '10.0.0.0/24', 'pools' => 'not-an-array'],
                        [
                            'subnet' => '10.0.1.0/24',
                            'pools' => [
                                'not-an-array',
                                ['no_pool_key' => true],
                                ['pool' => 'garbage'],
                                ['pool' => '999.999.999.999/24'],
                                ['pool' => '10.0.1.0/33'],
                                ['pool' => '10.0.1.0/abc'],
                                ['pool' => '10.0.1.20 - 10.0.1.10'],
                                ['pool' => '10.0.1.10 - 10.0.1.20'],
                            ],
                        ],
                    ],
                    'shared-networks' => [
                        'not-an-array',
                        ['name' => 'net', 'subnet4' => 'not-an-array'],
                    ],
                ],
            ],
        ]);

        $ranges = $this->service->getRanges()->values()->all();

        $this->assertCount(1, $ranges);
        $this->assertSame('10.0.1.10', $ranges[0]->rangeFrom);
        $this->assertSame('10.0.1.20', $ranges[0]->rangeTo);
    }

    public function test_computes_used_addresses_from_leases_inside_pool_bounds(): void
    {
        $this->fakeConfigGet(
            [
                'result' => 0,
                'arguments' => [
                    'Dhcp4' => [
                        'subnet4' => [
                            [
                                'subnet' => '10.0.0.0/24',
                                'pools' => [['pool' => '10.0.0.10 - 10.0.0.14']],
                            ],
                        ],
                    ],
                ],
            ],
            [
                [
                    'result' => 0,
                    'arguments' => [
                        'leases' => [
                            $this->keaLease('10.0.0.11', 'AA:BB:CC:00:00:01', 'in-range-1'),
                            $this->keaLease('10.0.0.13', 'AA:BB:CC:00:00:02', 'in-range-2'),
                            $this->keaLease('10.0.0.99', 'AA:BB:CC:00:00:03', 'out-of-range'),
                        ],
                    ],
                ],
                ['result' => 3],
            ],
        );

        $ranges = $this->service->getRanges()->values()->all();

        $this->assertCount(1, $ranges);
        $this->assertSame('5', $ranges[0]->totalAddresses);
        $this->assertSame(2, $ranges[0]->usedAddresses);
        $this->assertSame(0.4, $ranges[0]->utilisation);
    }

    public function test_get_pool_status_aggregates_across_multiple_ranges(): void
    {
        $this->fakeConfigGet(
            [
                'result' => 0,
                'arguments' => [
                    'Dhcp4' => [
                        'subnet4' => [
                            [
                                'subnet' => '10.0.0.0/24',
                                'pools' => [
                                    ['pool' => '10.0.0.10 - 10.0.0.14'],
                                    ['pool' => '10.0.0.20/30'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            [
                [
                    'result' => 0,
                    'arguments' => [
                        'leases' => [
                            $this->keaLease('10.0.0.11', 'AA:BB:CC:00:00:01', 'host-1'),
                            $this->keaLease('10.0.0.21', 'AA:BB:CC:00:00:02', 'host-2'),
                        ],
                    ],
                ],
                ['result' => 3],
            ],
        );

        $status = $this->service->getPoolStatus();

        $this->assertSame(9, $status->total);
        $this->assertSame(2, $status->used);
        $this->assertSame(7, $status->available);
        $this->assertSame(0.2222, $status->utilisation);
    }

    public function test_get_ranges_http_failure_propagates_uncaught(): void
    {
        Http::fake(['kea.local' => Http::response('Unauthorized', 401)]);

        $this->expectException(RequestException::class);

        $this->service->getRanges();
    }

    public function test_get_leases_returns_empty_collection_when_kea_reports_result_three(): void
    {
        Http::fake([
            'kea.local' => Http::response([
                ['result' => 3, 'text' => 'no leases found'],
            ]),
        ]);

        $leases = $this->service->getLeases();

        $this->assertInstanceOf(Collection::class, $leases);
        $this->assertTrue($leases->isEmpty());
    }

    public function test_get_leases_returns_empty_collection_when_leases_array_is_empty(): void
    {
        Http::fake([
            'kea.local' => Http::response([
                ['result' => 0, 'arguments' => ['leases' => []]],
            ]),
        ]);

        $leases = $this->service->getLeases();

        $this->assertTrue($leases->isEmpty());
    }

    public function test_first_page_requests_from_start_with_limit_1000(): void
    {
        Http::fake([
            'kea.local' => Http::response([
                ['result' => 3],
            ]),
        ]);

        $this->service->getLeases();

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return $data['command'] === 'lease4-get-page'
                && $data['arguments'] === ['from' => 'start', 'limit' => 1000];
        });
    }

    public function test_paginates_through_multiple_pages_assembling_all_leases(): void
    {
        Http::fake([
            'kea.local' => Http::sequence()
                ->push([
                    [
                        'result' => 0,
                        'arguments' => [
                            'leases' => [
                                $this->keaLease('10.0.0.1', 'AA:BB:CC:00:00:01', 'host-one'),
                                $this->keaLease('10.0.0.2', 'AA:BB:CC:00:00:02', 'host-two'),
                            ],
                        ],
                    ],
                ])
                ->push([
                    [
                        'result' => 0,
                        'arguments' => [
                            'leases' => [
                                $this->keaLease('10.0.0.3', 'AA:BB:CC:00:00:03', 'host-three'),
                            ],
                        ],
                    ],
                ])
                ->push([
                    ['result' => 3],
                ]),
        ]);

        $leases = $this->service->getLeases();

        $this->assertCount(3, $leases);
        $this->assertSame(['10.0.0.1', '10.0.0.2', '10.0.0.3'], $leases->pluck('ip')->all());

        $requests = [];
        Http::assertSent(function ($request) use (&$requests): bool {
            $requests[] = $request->data();

            return true;
        });

        $this->assertSame('start', $requests[0]['arguments']['from']);
        $this->assertSame('10.0.0.2', $requests[1]['arguments']['from']);
        $this->assertSame('10.0.0.3', $requests[2]['arguments']['from']);
    }

    public function test_excludes_declined_leases(): void
    {
        Http::fake([
            'kea.local' => Http::sequence()
                ->push([
                    [
                        'result' => 0,
                        'arguments' => [
                            'leases' => [
                                $this->keaLease('10.0.0.1', 'AA:BB:CC:00:00:01', 'declined-host', state: 1),
                            ],
                        ],
                    ],
                ])
                ->push([['result' => 3]]),
        ]);

        $leases = $this->service->getLeases();

        $this->assertTrue($leases->isEmpty());
    }

    public function test_excludes_expired_reclaimed_leases(): void
    {
        Http::fake([
            'kea.local' => Http::sequence()
                ->push([
                    [
                        'result' => 0,
                        'arguments' => [
                            'leases' => [
                                $this->keaLease('10.0.0.1', 'AA:BB:CC:00:00:01', 'reclaimed-host', state: 2),
                            ],
                        ],
                    ],
                ])
                ->push([['result' => 3]]),
        ]);

        $leases = $this->service->getLeases();

        $this->assertTrue($leases->isEmpty());
    }

    public function test_excludes_time_expired_state_zero_leases(): void
    {
        Http::fake([
            'kea.local' => Http::sequence()
                ->push([
                    [
                        'result' => 0,
                        'arguments' => [
                            'leases' => [
                                $this->keaLease(
                                    '10.0.0.1',
                                    'AA:BB:CC:00:00:01',
                                    'expired-host',
                                    state: 0,
                                    cltt: 1_999_999_000,
                                    validLft: 500,
                                ),
                            ],
                        ],
                    ],
                ])
                ->push([['result' => 3]]),
        ]);

        $leases = $this->service->getLeases();

        $this->assertTrue($leases->isEmpty());
    }

    public function test_maps_ip_mac_hostname_and_expiry_correctly(): void
    {
        Http::fake([
            'kea.local' => Http::sequence()
                ->push([
                    [
                        'result' => 0,
                        'arguments' => [
                            'leases' => [
                                $this->keaLease(
                                    '10.0.0.5',
                                    'AA:BB:CC:00:00:05',
                                    'my-host',
                                    state: 0,
                                    cltt: 1_999_999_999,
                                    validLft: 1000,
                                ),
                            ],
                        ],
                    ],
                ])
                ->push([['result' => 3]]),
        ]);

        $leases = $this->service->getLeases();

        $this->assertCount(1, $leases);

        $lease = $leases->first();
        $this->assertSame('10.0.0.5', $lease->ip);
        $this->assertSame('AA:BB:CC:00:00:05', $lease->mac);
        $this->assertSame('my-host', $lease->hostname);
        $this->assertSame(
            Carbon::createFromTimestamp(2_000_000_999)->toIso8601String(),
            $lease->expires,
        );
    }

    public function test_maps_missing_hostname_to_empty_string(): void
    {
        Http::fake([
            'kea.local' => Http::sequence()
                ->push([
                    [
                        'result' => 0,
                        'arguments' => [
                            'leases' => [
                                [
                                    'ip-address' => '10.0.0.9',
                                    'hw-address' => 'AA:BB:CC:00:00:09',
                                    'state' => 0,
                                    'cltt' => 1_999_999_999,
                                    'valid-lft' => 1000,
                                ],
                            ],
                        ],
                    ],
                ])
                ->push([['result' => 3]]),
        ]);

        $leases = $this->service->getLeases();

        $this->assertCount(1, $leases);
        $this->assertSame('', $leases->first()->hostname);
    }

    public function test_maps_missing_mac_to_null(): void
    {
        Http::fake([
            'kea.local' => Http::sequence()
                ->push([
                    [
                        'result' => 0,
                        'arguments' => [
                            'leases' => [
                                [
                                    'ip-address' => '10.0.0.9',
                                    'hostname' => 'no-mac-host',
                                    'state' => 0,
                                    'cltt' => 1_999_999_999,
                                    'valid-lft' => 1000,
                                ],
                            ],
                        ],
                    ],
                ])
                ->push([['result' => 3]]),
        ]);

        $leases = $this->service->getLeases();

        $this->assertCount(1, $leases);
        $this->assertNull($leases->first()->mac);
    }

    public function test_skips_lease_entries_missing_an_ip_address(): void
    {
        Http::fake([
            'kea.local' => Http::response([
                [
                    'result' => 0,
                    'arguments' => [
                        'leases' => [
                            [
                                'hostname' => 'no-ip-host',
                                'state' => 0,
                                'cltt' => 1_999_999_999,
                                'valid-lft' => 1000,
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $leases = $this->service->getLeases();

        $this->assertTrue($leases->isEmpty());
    }

    public function test_skips_non_array_lease_entries(): void
    {
        Http::fake([
            'kea.local' => Http::sequence()
                ->push([
                    [
                        'result' => 0,
                        'arguments' => [
                            'leases' => [
                                'not-an-array',
                                $this->keaLease('10.0.0.7', 'AA:BB:CC:00:00:07', 'valid-host'),
                            ],
                        ],
                    ],
                ])
                ->push([['result' => 3]]),
        ]);

        $leases = $this->service->getLeases();

        $this->assertCount(1, $leases);
        $this->assertSame('10.0.0.7', $leases->first()->ip);
    }

    public function test_http_failure_propagates_uncaught(): void
    {
        Http::fake(['kea.local' => Http::response('Unauthorized', 401)]);

        $this->expectException(RequestException::class);

        $this->service->getLeases();
    }

    public function test_connection_failure_propagates_uncaught(): void
    {
        Http::fake(['kea.local' => fn () => throw new ConnectionException('Connection refused')]);

        $this->expectException(ConnectionException::class);

        $this->service->getLeases();
    }

    public function test_malformed_response_propagates_uncaught(): void
    {
        Http::fake(['kea.local' => Http::response(['not' => 'a list'])]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unexpected response shape');

        $this->service->getLeases();
    }

    public function test_other_kea_error_result_propagates_uncaught(): void
    {
        Http::fake([
            'kea.local' => Http::response([
                ['result' => 1, 'text' => 'command not supported'],
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('command not supported');

        $this->service->getLeases();
    }

    public function test_get_lease_returns_lease_for_active_lease(): void
    {
        Http::fake([
            'kea.local' => Http::response([
                [
                    'result' => 0,
                    'arguments' => [
                        'state' => 0,
                        'cltt' => self::NOW_TIMESTAMP - 1_000,
                        'valid-lft' => 2_000,
                        'hw-address' => 'aa:bb:cc:dd:ee:ff',
                        'hostname' => 'workstation-1',
                    ],
                ],
            ]),
        ]);

        $lease = $this->service->getLease('192.168.1.50');

        $this->assertInstanceOf(DhcpLease::class, $lease);
        $this->assertSame('192.168.1.50', $lease->ip);
        $this->assertSame('aa:bb:cc:dd:ee:ff', $lease->mac);
        $this->assertSame('workstation-1', $lease->hostname);
        $this->assertSame(
            Carbon::createFromTimestamp(self::NOW_TIMESTAMP + 1_000)->toIso8601String(),
            $lease->expires,
        );

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return $data['command'] === 'lease4-get'
                && $data['arguments'] === ['ip-address' => '192.168.1.50'];
        });
    }

    public function test_get_lease_returns_null_when_lease_not_found(): void
    {
        Http::fake([
            'kea.local' => Http::response([
                ['result' => 3, 'text' => 'no leases found'],
            ]),
        ]);

        $this->assertNull($this->service->getLease('192.168.1.50'));
    }

    public function test_get_lease_returns_null_for_declined_lease(): void
    {
        Http::fake([
            'kea.local' => Http::response([
                [
                    'result' => 0,
                    'arguments' => [
                        'state' => 1,
                        'cltt' => self::NOW_TIMESTAMP - 1_000,
                        'valid-lft' => 2_000,
                        'hw-address' => 'aa:bb:cc:dd:ee:ff',
                    ],
                ],
            ]),
        ]);

        $this->assertNull($this->service->getLease('192.168.1.50'));
    }

    public function test_get_lease_returns_null_for_expired_reclaimed_lease(): void
    {
        Http::fake([
            'kea.local' => Http::response([
                [
                    'result' => 0,
                    'arguments' => [
                        'state' => 2,
                        'cltt' => self::NOW_TIMESTAMP - 1_000,
                        'valid-lft' => 2_000,
                        'hw-address' => 'aa:bb:cc:dd:ee:ff',
                    ],
                ],
            ]),
        ]);

        $this->assertNull($this->service->getLease('192.168.1.50'));
    }

    public function test_get_lease_returns_null_when_state_is_missing(): void
    {
        Http::fake([
            'kea.local' => Http::response([
                [
                    'result' => 0,
                    'arguments' => [
                        'cltt' => self::NOW_TIMESTAMP - 1_000,
                        'valid-lft' => 2_000,
                        'hw-address' => 'aa:bb:cc:dd:ee:ff',
                    ],
                ],
            ]),
        ]);

        $this->assertNull($this->service->getLease('192.168.1.50'));
    }

    public function test_get_lease_returns_null_when_expired_exactly_at_now(): void
    {
        Http::fake([
            'kea.local' => Http::response([
                [
                    'result' => 0,
                    'arguments' => [
                        'state' => 0,
                        'cltt' => self::NOW_TIMESTAMP - 1_000,
                        'valid-lft' => 1_000,
                        'hw-address' => 'aa:bb:cc:dd:ee:ff',
                    ],
                ],
            ]),
        ]);

        $this->assertNull($this->service->getLease('192.168.1.50'));
    }

    public function test_get_lease_returns_null_when_cltt_is_not_an_integer(): void
    {
        Http::fake([
            'kea.local' => Http::response([
                [
                    'result' => 0,
                    'arguments' => [
                        'state' => 0,
                        'cltt' => 'not-an-int',
                        'valid-lft' => 2_000,
                        'hw-address' => 'aa:bb:cc:dd:ee:ff',
                    ],
                ],
            ]),
        ]);

        $this->assertNull($this->service->getLease('192.168.1.50'));
    }

    public function test_get_lease_returns_null_when_valid_lft_is_not_an_integer(): void
    {
        Http::fake([
            'kea.local' => Http::response([
                [
                    'result' => 0,
                    'arguments' => [
                        'state' => 0,
                        'cltt' => self::NOW_TIMESTAMP - 1_000,
                        'valid-lft' => '2000',
                        'hw-address' => 'aa:bb:cc:dd:ee:ff',
                    ],
                ],
            ]),
        ]);

        $this->assertNull($this->service->getLease('192.168.1.50'));
    }

    public function test_get_lease_mac_is_null_when_hw_address_missing_and_hostname_defaults_to_empty_string(): void
    {
        Http::fake([
            'kea.local' => Http::response([
                [
                    'result' => 0,
                    'arguments' => [
                        'state' => 0,
                        'cltt' => self::NOW_TIMESTAMP - 1_000,
                        'valid-lft' => 2_000,
                    ],
                ],
            ]),
        ]);

        $lease = $this->service->getLease('192.168.1.50');

        $this->assertInstanceOf(DhcpLease::class, $lease);
        $this->assertNull($lease->mac);
        $this->assertSame('', $lease->hostname);
    }

    public function test_get_lease_mac_is_null_when_hw_address_is_empty_string(): void
    {
        Http::fake([
            'kea.local' => Http::response([
                [
                    'result' => 0,
                    'arguments' => [
                        'state' => 0,
                        'cltt' => self::NOW_TIMESTAMP - 1_000,
                        'valid-lft' => 2_000,
                        'hw-address' => '',
                    ],
                ],
            ]),
        ]);

        $lease = $this->service->getLease('192.168.1.50');

        $this->assertInstanceOf(DhcpLease::class, $lease);
        $this->assertNull($lease->mac);
    }

    public function test_get_lease_returns_null_on_connection_failure(): void
    {
        Http::fake(['kea.local' => fn () => throw new ConnectionException('Connection refused')]);

        $this->assertNull($this->service->getLease('192.168.1.50'));
    }

    public function test_get_lease_returns_null_on_http_error_response(): void
    {
        Http::fake(['kea.local' => Http::response('Internal Server Error', 500)]);

        $this->assertNull($this->service->getLease('192.168.1.50'));
    }

    public function test_get_lease_returns_null_on_kea_error_result_code(): void
    {
        Http::fake([
            'kea.local' => Http::response([
                ['result' => 1, 'text' => 'command not supported'],
            ]),
        ]);

        $this->assertNull($this->service->getLease('192.168.1.50'));
    }

    public function test_get_lease_returns_null_on_malformed_body(): void
    {
        Http::fake(['kea.local' => Http::response(['not' => 'a list'])]);

        $this->assertNull($this->service->getLease('192.168.1.50'));
    }

    public function test_get_lease_returns_null_for_non_ipv4_input_without_sending_a_request(): void
    {
        Http::fake();

        $this->assertNull($this->service->getLease('2001:db8::1'));

        Http::assertNothingSent();
    }

    /**
     * @param  array<string, mixed>  $configGetResponse
     * @param  list<array<string, mixed>>  $leaseResponses
     */
    private function fakeConfigGet(array $configGetResponse, array $leaseResponses = [['result' => 3]]): void
    {
        $leaseQueue = $leaseResponses;

        Http::fake([
            'kea.local' => function ($request) use ($configGetResponse, &$leaseQueue) {
                $command = $request->data()['command'] ?? null;

                if ($command === 'config-get') {
                    return Http::response([$configGetResponse]);
                }

                $next = array_shift($leaseQueue) ?? ['result' => 3];

                return Http::response([$next]);
            },
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function keaLease(
        string $ip,
        string $mac,
        string $hostname,
        int $state = 0,
        int $cltt = 1_999_999_999,
        int $validLft = 1000,
    ): array {
        return [
            'ip-address' => $ip,
            'hw-address' => $mac,
            'hostname' => $hostname,
            'state' => $state,
            'cltt' => $cltt,
            'valid-lft' => $validLft,
        ];
    }
}
