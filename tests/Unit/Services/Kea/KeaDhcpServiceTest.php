<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Kea;

use App\Services\Interfaces\DhcpInterface;
use App\Services\Kea\KeaClient;
use App\Services\Kea\KeaDhcpService;
use App\Services\ValueObjects\DhcpLease;
use App\Services\ValueObjects\DhcpPoolStatus;
use App\Services\ValueObjects\DhcpRange;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class KeaDhcpServiceTest extends TestCase
{
    private const NOW_TIMESTAMP = 2_000_000_000;

    private KeaDhcpService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Date::setTestNow(Date::createFromTimestamp(self::NOW_TIMESTAMP));
        $this->service = new KeaDhcpService(new KeaClient(endpoint: 'https://kea.local'));
    }

    protected function tearDown(): void
    {
        Date::setTestNow();
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

    public function test_declined_leases_count_towards_used_but_are_not_returned_as_leases(): void
    {
        $this->fakeConfigGet(
            [
                'result' => 0,
                'arguments' => ['Dhcp4' => ['subnet4' => [
                    ['subnet' => '10.0.0.0/24', 'pools' => [['pool' => '10.0.0.10 - 10.0.0.14']]],
                ]]],
            ],
            [
                [
                    'result' => 0,
                    'arguments' => ['leases' => [
                        $this->keaLease('10.0.0.11', 'AA:BB:CC:00:00:01', 'active'),
                        $this->keaLease('10.0.0.12', 'AA:BB:CC:00:00:02', 'declined', state: 1),
                    ]],
                ],
                ['result' => 3],
            ],
        );

        $this->assertCount(1, $this->service->getLeases());
        $this->assertSame(2, $this->service->getRanges()->first()->usedAddresses);
        $this->assertSame(2, $this->service->getPoolStatus()->used);
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

    public function test_get_ranges_returns_empty_and_reports_failure_when_ipv4_fetch_fails(): void
    {
        Http::fake(['kea.local' => Http::response('Unauthorized', 401)]);

        $ranges = $this->service->getRanges();

        $this->assertTrue($ranges->isEmpty());
        $this->assertFalse($this->service->getFetchStatus()['ipv4']);
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

        $lease = $this->assertLease($leases->first());
        $this->assertSame('10.0.0.5', $lease->ip);
        $this->assertSame('AA:BB:CC:00:00:05', $lease->mac);
        $this->assertSame('my-host', $lease->hostname);
        $this->assertSame(
            Date::createFromTimestamp(2_000_000_999)->toIso8601String(),
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
        $this->assertNull($this->assertLease($leases->first())->hostname);
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
        $this->assertNull($this->assertLease($leases->first())->mac);
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
        $this->assertSame('10.0.0.7', $this->assertLease($leases->first())->ip);
    }

    public static function leasesFetchFailureProvider(): array
    {
        return [
            'http failure' => [fn () => Http::fake(['kea.local' => Http::response('Unauthorized', 401)])],
            'connection failure' => [fn () => Http::fake(['kea.local' => fn () => throw new ConnectionException('Connection refused')])],
            'malformed response' => [fn () => Http::fake(['kea.local' => Http::response(['not' => 'a list'])])],
            'other kea error result' => [fn () => Http::fake([
                'kea.local' => Http::response([
                    ['result' => 1, 'text' => 'command not supported'],
                ]),
            ])],
        ];
    }

    #[DataProvider('leasesFetchFailureProvider')]
    public function test_lease_fetch_failure_is_caught_and_reported_via_fetch_status(Closure $fakeHttp): void
    {
        $fakeHttp();

        $leases = $this->service->getLeases();

        $this->assertTrue($leases->isEmpty());
        $this->assertFalse($this->service->getFetchStatus()['ipv4']);
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
            Date::createFromTimestamp(self::NOW_TIMESTAMP + 1_000)->toIso8601String(),
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
        $this->assertNull($lease->hostname);
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

    public static function getLeaseFailureProvider(): array
    {
        return [
            'connection failure' => [fn () => Http::fake(['kea.local' => fn () => throw new ConnectionException('Connection refused')])],
            'http error response' => [fn () => Http::fake(['kea.local' => Http::response('Internal Server Error', 500)])],
            'kea error result code' => [fn () => Http::fake([
                'kea.local' => Http::response([
                    ['result' => 1, 'text' => 'command not supported'],
                ]),
            ])],
            'malformed body' => [fn () => Http::fake(['kea.local' => Http::response(['not' => 'a list'])])],
        ];
    }

    #[DataProvider('getLeaseFailureProvider')]
    public function test_get_lease_returns_null_on_fetch_failure(Closure $fakeHttp): void
    {
        $fakeHttp();

        $this->assertNull($this->service->getLease('192.168.1.50'));
    }

    public function test_get_lease_returns_null_for_ipv6_input_when_ipv6_client_not_configured_without_sending_a_request(): void
    {
        // Covers both "not an IPv4 address" and "no IPv6 client configured" for
        // this input: the default setUp() service has no IPv6 endpoint, so a
        // valid IPv6 address is rejected before any HTTP request is made.
        Http::fake();

        $this->assertNull($this->service->getLease('2001:db8::1'));

        Http::assertNothingSent();
    }

    public function test_get_lease_returns_null_for_input_that_is_neither_ipv4_nor_ipv6(): void
    {
        $service = $this->dualStackService();

        Http::fake();

        $this->assertNull($service->getLease('not-an-ip-address'));

        Http::assertNothingSent();
    }

    public function test_get_lease_returns_lease_for_active_ipv6_lease_using_hw_address(): void
    {
        $service = $this->dualStackService();

        Http::fake([
            'kea6.local' => Http::response([
                [
                    'result' => 0,
                    'arguments' => [
                        'state' => 0,
                        'cltt' => self::NOW_TIMESTAMP - 1_000,
                        'valid-lft' => 2_000,
                        'hw-address' => 'AA:BB:CC:00:00:01',
                        'hostname' => 'workstation-6',
                        'type' => 'IA_NA',
                    ],
                ],
            ]),
        ]);

        $lease = $service->getLease('2001:db8::1');

        $this->assertInstanceOf(DhcpLease::class, $lease);
        $this->assertSame('2001:db8::1', $lease->ip);
        $this->assertSame('AA:BB:CC:00:00:01', $lease->mac);
        $this->assertSame('workstation-6', $lease->hostname);
        $this->assertSame(
            Date::createFromTimestamp(self::NOW_TIMESTAMP + 1_000)->toIso8601String(),
            $lease->expires,
        );

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return $data['command'] === 'lease6-get'
                && $data['arguments'] === ['ip-address' => '2001:db8::1', 'type' => 'IA_NA'];
        });
    }

    public function test_get_lease_derives_mac_from_duid_when_hw_address_missing_for_ipv6(): void
    {
        $service = $this->dualStackService();

        Http::fake([
            'kea6.local' => Http::response([
                [
                    'result' => 0,
                    'arguments' => [
                        'state' => 0,
                        'cltt' => self::NOW_TIMESTAMP - 1_000,
                        'valid-lft' => 2_000,
                        'duid' => '00010001AABBCCDDEEFF0011AABB',
                    ],
                ],
            ]),
        ]);

        $lease = $service->getLease('2001:db8::1');

        $this->assertInstanceOf(DhcpLease::class, $lease);
        $this->assertSame('EE:FF:00:11:AA:BB', $lease->mac);
    }

    public function test_get_lease_ipv6_mac_is_null_when_hw_address_and_duid_absent(): void
    {
        $service = $this->dualStackService();

        Http::fake([
            'kea6.local' => Http::response([
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

        $lease = $service->getLease('2001:db8::1');

        $this->assertInstanceOf(DhcpLease::class, $lease);
        $this->assertNull($lease->mac);
    }

    public function test_get_lease_returns_null_for_inactive_ipv6_lease(): void
    {
        $service = $this->dualStackService();

        Http::fake([
            'kea6.local' => Http::response([
                [
                    'result' => 0,
                    'arguments' => [
                        'state' => 1,
                        'cltt' => self::NOW_TIMESTAMP - 1_000,
                        'valid-lft' => 2_000,
                        'hw-address' => 'AA:BB:CC:00:00:01',
                    ],
                ],
            ]),
        ]);

        $this->assertNull($service->getLease('2001:db8::1'));
    }

    public function test_get_lease_returns_null_when_ipv6_lease_not_found(): void
    {
        $service = $this->dualStackService();

        Http::fake([
            'kea6.local' => Http::response([
                ['result' => 3, 'text' => 'no leases found'],
            ]),
        ]);

        $this->assertNull($service->getLease('2001:db8::1'));
    }

    public function test_get_lease_returns_null_on_ipv6_connection_failure(): void
    {
        $service = $this->dualStackService();

        Http::fake(['kea6.local' => fn () => throw new ConnectionException('Connection refused')]);

        $this->assertNull($service->getLease('2001:db8::1'));
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

    /**
     * @return array<string, mixed>
     */
    private function keaLease6(
        string $ip,
        ?string $hwAddress = null,
        ?string $duid = null,
        string $hostname = '',
        int $state = 0,
        int $cltt = 1_999_999_999,
        int $validLft = 1000,
        string $type = 'IA_NA',
    ): array {
        $lease = [
            'ip-address' => $ip,
            'hostname' => $hostname,
            'state' => $state,
            'cltt' => $cltt,
            'valid-lft' => $validLft,
            'type' => $type,
        ];

        if ($hwAddress !== null) {
            $lease['hw-address'] = $hwAddress;
        }

        if ($duid !== null) {
            $lease['duid'] = $duid;
        }

        return $lease;
    }

    private function assertLease(mixed $lease): DhcpLease
    {
        if (! $lease instanceof DhcpLease) {
            $this->fail('Expected a DhcpLease instance.');
        }

        return $lease;
    }

    private function dualStackService(): KeaDhcpService
    {
        return new KeaDhcpService(
            new KeaClient(endpoint: 'https://kea4.local'),
            new KeaClient(endpoint: 'https://kea6.local', service: 'dhcp6'),
        );
    }

    /**
     * @param  array<string, mixed>  $dhcp4Arguments
     * @param  array<string, mixed>  $dhcp6Arguments
     * @param  list<array<string, mixed>>  $ipv4LeaseResponses
     * @param  list<array<string, mixed>>  $ipv6LeaseResponses
     */
    private function fakeDualStackConfigGet(
        array $dhcp4Arguments,
        array $dhcp6Arguments,
        array $ipv4LeaseResponses = [['result' => 3]],
        array $ipv6LeaseResponses = [['result' => 3]],
    ): void {
        $ipv4Queue = $ipv4LeaseResponses;
        $ipv6Queue = $ipv6LeaseResponses;

        Http::fake([
            'kea4.local' => function ($request) use ($dhcp4Arguments, &$ipv4Queue) {
                if (($request->data()['command'] ?? null) === 'config-get') {
                    return Http::response([['result' => 0, 'arguments' => $dhcp4Arguments]]);
                }

                return Http::response([array_shift($ipv4Queue) ?? ['result' => 3]]);
            },
            'kea6.local' => function ($request) use ($dhcp6Arguments, &$ipv6Queue) {
                if (($request->data()['command'] ?? null) === 'config-get') {
                    return Http::response([['result' => 0, 'arguments' => $dhcp6Arguments]]);
                }

                return Http::response([array_shift($ipv6Queue) ?? ['result' => 3]]);
            },
        ]);
    }

    public function test_ipv6_first_page_requests_from_start_with_limit_1000(): void
    {
        $service = $this->dualStackService();

        Http::fake([
            'kea4.local' => Http::response([['result' => 3]]),
            'kea6.local' => Http::response([['result' => 3]]),
        ]);

        $service->getLeases();

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return $request->url() === 'https://kea6.local'
                && $data['command'] === 'lease6-get-page'
                && $data['arguments'] === ['from' => 'start', 'limit' => 1000];
        });
    }

    public function test_ipv6_paginates_through_multiple_pages(): void
    {
        $service = $this->dualStackService();

        Http::fake([
            'kea4.local' => Http::response([['result' => 3]]),
            'kea6.local' => Http::sequence()
                ->push([[
                    'result' => 0,
                    'arguments' => [
                        'leases' => [
                            $this->keaLease6('2001:db8::1', hwAddress: 'AA:BB:CC:00:00:01'),
                            $this->keaLease6('2001:db8::2', hwAddress: 'AA:BB:CC:00:00:02'),
                        ],
                    ],
                ]])
                ->push([[
                    'result' => 0,
                    'arguments' => [
                        'leases' => [
                            $this->keaLease6('2001:db8::3', hwAddress: 'AA:BB:CC:00:00:03'),
                        ],
                    ],
                ]])
                ->push([['result' => 3]]),
        ]);

        $leases = $service->getLeases();

        $this->assertSame(['2001:db8::1', '2001:db8::2', '2001:db8::3'], $leases->pluck('ip')->all());
    }

    public function test_ipv6_excludes_declined_reclaimed_and_time_expired_leases(): void
    {
        $service = $this->dualStackService();

        Http::fake([
            'kea4.local' => Http::response([['result' => 3]]),
            'kea6.local' => Http::sequence()
                ->push([[
                    'result' => 0,
                    'arguments' => [
                        'leases' => [
                            $this->keaLease6('2001:db8::1', hwAddress: 'AA:BB:CC:00:00:01', state: 1),
                            $this->keaLease6('2001:db8::2', hwAddress: 'AA:BB:CC:00:00:02', state: 2),
                            $this->keaLease6(
                                '2001:db8::3',
                                hwAddress: 'AA:BB:CC:00:00:03',
                                state: 0,
                                cltt: 1_999_999_000,
                                validLft: 500,
                            ),
                        ],
                    ],
                ]])
                ->push([['result' => 3]]),
        ]);

        $leases = $service->getLeases();

        $this->assertTrue($leases->isEmpty());
    }

    public function test_ipv6_skips_ia_pd_entries_but_advances_cursor(): void
    {
        $service = $this->dualStackService();

        Http::fake([
            'kea4.local' => Http::response([['result' => 3]]),
            'kea6.local' => Http::sequence()
                ->push([[
                    'result' => 0,
                    'arguments' => [
                        'leases' => [
                            $this->keaLease6('2001:db8::1', hwAddress: 'AA:BB:CC:00:00:01', type: 'IA_PD'),
                            $this->keaLease6('2001:db8::2', hwAddress: 'AA:BB:CC:00:00:02', type: 'IA_NA'),
                        ],
                    ],
                ]])
                ->push([['result' => 3]]),
        ]);

        $leases = $service->getLeases();

        $this->assertCount(1, $leases);
        $this->assertSame('2001:db8::2', $this->assertLease($leases->first())->ip);

        Http::assertSentCount(3);
    }

    public function test_ipv6_mac_uses_hw_address_when_present(): void
    {
        $service = $this->dualStackService();

        Http::fake([
            'kea4.local' => Http::response([['result' => 3]]),
            'kea6.local' => Http::sequence()
                ->push([[
                    'result' => 0,
                    'arguments' => [
                        'leases' => [
                            $this->keaLease6('2001:db8::1', hwAddress: 'AA:BB:CC:00:00:01', duid: '00030001AABBCCDDEEFF'),
                        ],
                    ],
                ]])
                ->push([['result' => 3]]),
        ]);

        $leases = $service->getLeases();

        $this->assertSame('AA:BB:CC:00:00:01', $this->assertLease($leases->first())->mac);
    }

    public function test_ipv6_mac_extracted_from_duid_llt_when_hw_address_missing(): void
    {
        $service = $this->dualStackService();

        Http::fake([
            'kea4.local' => Http::response([['result' => 3]]),
            'kea6.local' => Http::sequence()
                ->push([[
                    'result' => 0,
                    'arguments' => [
                        'leases' => [
                            $this->keaLease6('2001:db8::1', duid: '00010001AABBCCDDEEFF0011AABB'),
                        ],
                    ],
                ]])
                ->push([['result' => 3]]),
        ]);

        $leases = $service->getLeases();

        $this->assertSame('EE:FF:00:11:AA:BB', $this->assertLease($leases->first())->mac);
    }

    public function test_ipv6_mac_extracted_from_duid_ll_when_hw_address_missing(): void
    {
        $service = $this->dualStackService();

        Http::fake([
            'kea4.local' => Http::response([['result' => 3]]),
            'kea6.local' => Http::sequence()
                ->push([[
                    'result' => 0,
                    'arguments' => [
                        'leases' => [
                            $this->keaLease6('2001:db8::1', duid: '00030001AABBCCDDEEFF'),
                        ],
                    ],
                ]])
                ->push([['result' => 3]]),
        ]);

        $leases = $service->getLeases();

        $this->assertSame('AA:BB:CC:DD:EE:FF', $this->assertLease($leases->first())->mac);
    }

    public function test_ipv6_mac_null_for_unsupported_duid_non_ethernet_or_missing_duid(): void
    {
        $service = $this->dualStackService();

        $duidTypeEn = '00020000000AABBCCDD';
        $duidLlNonEthernetHardware = '00030006AABBCCDDEEFF';

        Http::fake([
            'kea4.local' => Http::response([['result' => 3]]),
            'kea6.local' => Http::sequence()
                ->push([[
                    'result' => 0,
                    'arguments' => [
                        'leases' => [
                            $this->keaLease6('2001:db8::1', duid: $duidTypeEn),
                            $this->keaLease6('2001:db8::2', duid: $duidLlNonEthernetHardware),
                            $this->keaLease6('2001:db8::3'),
                            $this->keaLease6('2001:db8::4', hwAddress: '', duid: ''),
                        ],
                    ],
                ]])
                ->push([['result' => 3]]),
        ]);

        $leases = $service->getLeases()->keyBy('ip');

        $this->assertNull($this->assertLease($leases['2001:db8::1'])->mac);
        $this->assertNull($this->assertLease($leases['2001:db8::2'])->mac);
        $this->assertNull($this->assertLease($leases['2001:db8::3'])->mac);
        $this->assertNull($this->assertLease($leases['2001:db8::4'])->mac);
    }

    public function test_get_leases_combines_ipv4_and_ipv6(): void
    {
        $service = $this->dualStackService();

        Http::fake([
            'kea4.local' => Http::sequence()
                ->push([[
                    'result' => 0,
                    'arguments' => ['leases' => [$this->keaLease('10.0.0.1', 'AA:BB:CC:00:00:01', 'v4-host')]],
                ]])
                ->push([['result' => 3]]),
            'kea6.local' => Http::sequence()
                ->push([[
                    'result' => 0,
                    'arguments' => ['leases' => [$this->keaLease6('2001:db8::1', hwAddress: 'AA:BB:CC:00:00:02')]],
                ]])
                ->push([['result' => 3]]),
        ]);

        $leases = $service->getLeases();

        $this->assertCount(2, $leases);
        $this->assertSame(['10.0.0.1', '2001:db8::1'], $leases->pluck('ip')->all());
    }

    public function test_get_fetch_status_reports_true_true_on_dual_success(): void
    {
        $service = $this->dualStackService();

        Http::fake([
            'kea4.local' => Http::response([['result' => 3]]),
            'kea6.local' => Http::response([['result' => 3]]),
        ]);

        $this->assertSame(
            ['ipv4' => true, 'ipv6' => true, 'ipv4_ranges' => true, 'ipv6_ranges' => true],
            $service->getFetchStatus(),
        );
    }

    public function test_ipv6_failure_does_not_block_or_corrupt_ipv4(): void
    {
        $service = $this->dualStackService();

        Http::fake([
            'kea4.local' => Http::sequence()
                ->push([[
                    'result' => 0,
                    'arguments' => ['leases' => [$this->keaLease('10.0.0.1', 'AA:BB:CC:00:00:01', 'v4-host')]],
                ]])
                ->push([['result' => 3]]),
            'kea6.local' => Http::response('Unauthorized', 401),
        ]);

        $leases = $service->getLeases();
        $status = $service->getFetchStatus();

        $this->assertSame(['10.0.0.1'], $leases->pluck('ip')->all());
        $this->assertTrue($status['ipv4']);
        $this->assertFalse($status['ipv6']);
    }

    public function test_ipv4_failure_does_not_block_or_corrupt_ipv6(): void
    {
        $service = $this->dualStackService();

        Http::fake([
            'kea4.local' => Http::response('Unauthorized', 401),
            'kea6.local' => Http::sequence()
                ->push([[
                    'result' => 0,
                    'arguments' => ['leases' => [$this->keaLease6('2001:db8::1', hwAddress: 'AA:BB:CC:00:00:02')]],
                ]])
                ->push([['result' => 3]]),
        ]);

        $leases = $service->getLeases();
        $status = $service->getFetchStatus();

        $this->assertSame(['2001:db8::1'], $leases->pluck('ip')->all());
        $this->assertFalse($status['ipv4']);
        $this->assertTrue($status['ipv6']);
    }

    public function test_unconfigured_ipv6_client_makes_no_request_and_reports_success(): void
    {
        Http::fake([
            'kea.local' => Http::response([['result' => 3]]),
            'kea6.local' => Http::response([['result' => 3]]),
        ]);

        $status = $this->service->getFetchStatus();

        $this->assertTrue($status['ipv4']);
        $this->assertTrue($status['ipv6']);
        $this->assertTrue($this->service->getLeases()->isEmpty());

        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://kea6.local');
    }

    public function test_reset_snapshot_clears_cache_and_a_subsequent_call_refetches(): void
    {
        Http::fake(['kea.local' => Http::response([['result' => 3]])]);

        $this->service->getFetchStatus();
        Http::assertSentCount(1);

        $this->service->getFetchStatus();
        Http::assertSentCount(1);

        $this->service->resetSnapshot();
        $this->service->getFetchStatus();
        Http::assertSentCount(2);
    }

    public function test_get_ranges_then_get_pool_status_only_sends_config_get_once(): void
    {
        $this->fakeConfigGet([
            'result' => 0,
            'arguments' => ['Dhcp4' => ['subnet4' => []]],
        ]);

        $this->service->getRanges();
        $this->service->getPoolStatus();

        $configGetCount = 0;
        Http::assertSent(function ($request) use (&$configGetCount): bool {
            if (($request->data()['command'] ?? null) === 'config-get') {
                $configGetCount++;
            }

            return true;
        });

        $this->assertSame(1, $configGetCount);
    }

    public function test_get_ranges_returns_empty_and_reports_failure_when_config_get_itself_fails(): void
    {
        Http::fake([
            'kea.local' => function ($request) {
                $command = $request->data()['command'] ?? null;

                if ($command === 'config-get') {
                    return Http::response('Unauthorized', 401);
                }

                return Http::response([['result' => 3]]);
            },
        ]);

        $ranges = $this->service->getRanges();
        $status = $this->service->getFetchStatus();

        $this->assertTrue($ranges->isEmpty());
        $this->assertFalse($status['ipv4_ranges']);
    }

    public function test_config_get_failure_does_not_corrupt_already_fetched_ipv4_lease_status(): void
    {
        $leasePageQueue = [[
            'result' => 0,
            'arguments' => ['leases' => [$this->keaLease('10.0.0.1', 'AA:BB:CC:00:00:01', 'v4-host')]],
        ]];

        Http::fake([
            'kea.local' => function ($request) use (&$leasePageQueue) {
                $command = $request->data()['command'] ?? null;

                if ($command === 'config-get') {
                    return Http::response('Unauthorized', 401);
                }

                if ($command === 'lease4-get-page') {
                    return Http::response([array_shift($leasePageQueue) ?? ['result' => 3]]);
                }

                return Http::response([['result' => 3]]);
            },
        ]);

        $leases = $this->service->getLeases();
        $ranges = $this->service->getRanges();
        $status = $this->service->getFetchStatus();

        $this->assertSame(['10.0.0.1'], $leases->pluck('ip')->all());
        $this->assertTrue($ranges->isEmpty());
        $this->assertTrue($status['ipv4']);
        $this->assertFalse($status['ipv4_ranges']);
    }

    public function test_get_ranges_returns_empty_without_request_when_ipv4_client_is_null(): void
    {
        Http::fake();

        $service = new KeaDhcpService(null);

        $ranges = $service->getRanges();

        $this->assertTrue($ranges->isEmpty());
        Http::assertNothingSent();
    }

    public function test_ipv6_parses_range_and_cidr_pools_including_shared_networks(): void
    {
        $service = $this->dualStackService();

        $this->fakeDualStackConfigGet(
            ['Dhcp4' => ['subnet4' => []]],
            [
                'Dhcp6' => [
                    'subnet6' => [
                        [
                            'subnet' => '2001:db8:1::/64',
                            'interface' => 'eth6',
                            'pools' => [
                                ['pool' => '2001:db8:1::10 - 2001:db8:1::20'],
                            ],
                        ],
                    ],
                    'shared-networks' => [
                        [
                            'name' => 'v6-office',
                            'subnet6' => [
                                [
                                    'subnet' => '2001:db8:2::/64',
                                    'pools' => [
                                        ['pool' => '2001:db8:2::/126'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        );

        $ranges = $service->getRanges()->values()->all();

        $this->assertCount(2, $ranges);
        $this->assertSame('ipv6', $ranges[0]->type);
        $this->assertSame('eth6', $ranges[0]->interface);
        $this->assertSame('2001:db8:1::/64', $ranges[0]->subnet);
        $this->assertSame('2001:db8:1::10', $ranges[0]->rangeFrom);
        $this->assertSame('2001:db8:1::20', $ranges[0]->rangeTo);
        $this->assertSame('17', $ranges[0]->totalAddresses);

        $this->assertSame('v6-office', $ranges[1]->interface);
        $this->assertSame('2001:db8:2::', $ranges[1]->rangeFrom);
        $this->assertSame('2001:db8:2::3', $ranges[1]->rangeTo);
        $this->assertSame('4', $ranges[1]->totalAddresses);
    }

    public function test_ipv6_pd_pools_are_ignored(): void
    {
        $service = $this->dualStackService();

        $this->fakeDualStackConfigGet(
            ['Dhcp4' => ['subnet4' => []]],
            [
                'Dhcp6' => [
                    'subnet6' => [
                        [
                            'subnet' => '2001:db8::/64',
                            'pools' => [['pool' => '2001:db8::10 - 2001:db8::20']],
                            'pd-pools' => [['prefix' => '2001:db8:ffff::', 'prefix-len' => 48, 'delegated-len' => 64]],
                        ],
                    ],
                ],
            ],
        );

        $ranges = $service->getRanges()->values()->all();

        $this->assertCount(1, $ranges);
        $this->assertSame('2001:db8::10', $ranges[0]->rangeFrom);
        $this->assertSame('2001:db8::20', $ranges[0]->rangeTo);
    }

    public function test_ipv6_label_fallback_order(): void
    {
        $service = $this->dualStackService();

        $this->fakeDualStackConfigGet(
            ['Dhcp4' => ['subnet4' => []]],
            [
                'Dhcp6' => [
                    'subnet6' => [
                        [
                            'subnet' => '2001:db8::/64',
                            'user-context' => ['name' => 'subnet-name'],
                            'pools' => [
                                ['pool' => '2001:db8::10 - 2001:db8::20', 'user-context' => ['name' => 'pool-name']],
                                ['pool' => '2001:db8::30 - 2001:db8::40'],
                            ],
                        ],
                        [
                            'subnet' => '2001:db8:1::/64',
                            'pools' => [
                                ['pool' => '2001:db8:1::10 - 2001:db8:1::20'],
                            ],
                        ],
                    ],
                ],
            ],
        );

        $ranges = $service->getRanges()->values()->all();

        $this->assertCount(3, $ranges);
        $this->assertSame('pool-name', $ranges[0]->description);
        $this->assertSame('subnet-name', $ranges[1]->description);
        $this->assertSame(
            "2001:db8:1::/64 (2001:db8:1::10\u{2013}2001:db8:1::20)",
            $ranges[2]->description,
        );
    }

    public function test_ipv6_skips_malformed_subnet_and_pool_entries(): void
    {
        $service = $this->dualStackService();

        $this->fakeDualStackConfigGet(
            ['Dhcp4' => ['subnet4' => []]],
            [
                'Dhcp6' => [
                    'subnet6' => [
                        'not-an-array',
                        ['pools' => [['pool' => '2001:db8::10 - 2001:db8::20']]],
                        ['subnet' => 123, 'pools' => [['pool' => '2001:db8::10 - 2001:db8::20']]],
                        ['subnet' => '2001:db8::/64', 'pools' => 'not-an-array'],
                        [
                            'subnet' => '2001:db8:1::/64',
                            'pools' => [
                                'not-an-array',
                                ['no_pool_key' => true],
                                ['pool' => 'garbage'],
                                ['pool' => '999.999.999.999/64'],
                                ['pool' => '2001:db8:1::/129'],
                                ['pool' => '2001:db8:1::/abc'],
                                ['pool' => '2001:db8:1::20 - 2001:db8:1::10'],
                                ['pool' => 'garbage - 2001:db8:1::10'],
                                ['pool' => '2001:db8:1::10 - garbage'],
                                ['pool' => '2001:db8:1::10 - 2001:db8:1::20'],
                            ],
                        ],
                    ],
                    'shared-networks' => [
                        'not-an-array',
                        ['name' => 'net', 'subnet6' => 'not-an-array'],
                    ],
                ],
            ],
        );

        $ranges = $service->getRanges()->values()->all();

        $this->assertCount(1, $ranges);
        $this->assertSame('2001:db8:1::10', $ranges[0]->rangeFrom);
        $this->assertSame('2001:db8:1::20', $ranges[0]->rangeTo);
    }

    public function test_ipv6_config_get_arguments_non_array_returns_empty_ipv6_ranges(): void
    {
        $service = $this->dualStackService();

        Http::fake([
            'kea4.local' => function ($request) {
                if (($request->data()['command'] ?? null) === 'config-get') {
                    return Http::response([['result' => 0, 'arguments' => ['Dhcp4' => ['subnet4' => []]]]]);
                }

                return Http::response([['result' => 3]]);
            },
            'kea6.local' => function ($request) {
                if (($request->data()['command'] ?? null) === 'config-get') {
                    return Http::response([['result' => 0, 'arguments' => 'not-an-array']]);
                }

                return Http::response([['result' => 3]]);
            },
        ]);

        $this->assertTrue($service->getRanges()->isEmpty());
    }

    public function test_ipv6_dhcp6_missing_or_non_array_returns_empty_ipv6_ranges(): void
    {
        $service = $this->dualStackService();

        $this->fakeDualStackConfigGet(
            ['Dhcp4' => ['subnet4' => []]],
            ['SomethingElse' => []],
        );

        $this->assertTrue($service->getRanges()->isEmpty());

        $service->resetSnapshot();

        $this->fakeDualStackConfigGet(
            ['Dhcp4' => ['subnet4' => []]],
            ['Dhcp6' => 'not-an-array'],
        );

        $this->assertTrue($service->getRanges()->isEmpty());
    }

    public function test_ipv6_get_ranges_returns_ipv4_only_when_no_ipv6_subnets_configured(): void
    {
        $service = $this->dualStackService();

        $this->fakeDualStackConfigGet(
            [
                'Dhcp4' => [
                    'subnet4' => [
                        ['subnet' => '10.0.0.0/24', 'pools' => [['pool' => '10.0.0.10 - 10.0.0.14']]],
                    ],
                ],
            ],
            ['Dhcp6' => ['subnet6' => []]],
        );

        $ranges = $service->getRanges()->values()->all();

        $this->assertCount(1, $ranges);
        $this->assertSame('ipv4', $ranges[0]->type);
    }

    public function test_ipv6_computes_used_addresses_within_pool_bounds_not_whole_subnet(): void
    {
        $service = $this->dualStackService();

        $this->fakeDualStackConfigGet(
            ['Dhcp4' => ['subnet4' => []]],
            [
                'Dhcp6' => [
                    'subnet6' => [
                        [
                            'subnet' => '2001:db8::/64',
                            'pools' => [['pool' => '2001:db8::10 - 2001:db8::14']],
                        ],
                    ],
                ],
            ],
            ipv6LeaseResponses: [
                [
                    'result' => 0,
                    'arguments' => [
                        'leases' => [
                            $this->keaLease6('2001:db8::11', hwAddress: 'AA:BB:CC:00:00:01'),
                            $this->keaLease6('2001:db8::13', hwAddress: 'AA:BB:CC:00:00:02'),
                            $this->keaLease6('2001:db8::99', hwAddress: 'AA:BB:CC:00:00:03'),
                            $this->keaLease6('not-an-ip', hwAddress: 'AA:BB:CC:00:00:04'),
                        ],
                    ],
                ],
                ['result' => 3],
            ],
        );

        $ranges = $service->getRanges()->values()->all();

        $this->assertCount(1, $ranges);
        $this->assertSame('5', $ranges[0]->totalAddresses);
        $this->assertSame(2, $ranges[0]->usedAddresses);
        $this->assertSame(0.4, $ranges[0]->utilisation);
    }

    public function test_ipv6_slash_64_pool_total_is_capped_with_correct_used(): void
    {
        $service = $this->dualStackService();

        $this->fakeDualStackConfigGet(
            [
                'Dhcp4' => [
                    'subnet4' => [
                        ['subnet' => '10.0.0.0/24', 'pools' => [['pool' => '10.0.0.10 - 10.0.0.14']]],
                    ],
                ],
            ],
            [
                'Dhcp6' => [
                    'subnet6' => [
                        [
                            'subnet' => '2a0f:85c1:d91:2100::/64',
                            'pools' => [['pool' => '2a0f:85c1:d91:2100::/64']],
                        ],
                    ],
                ],
            ],
            ipv4LeaseResponses: [
                [
                    'result' => 0,
                    'arguments' => [
                        'leases' => [$this->keaLease('10.0.0.11', 'AA:BB:CC:00:00:01', 'v4-host')],
                    ],
                ],
                ['result' => 3],
            ],
            ipv6LeaseResponses: [
                [
                    'result' => 0,
                    'arguments' => [
                        'leases' => [
                            $this->keaLease6('2a0f:85c1:d91:2100::1', hwAddress: 'AA:BB:CC:00:00:02'),
                        ],
                    ],
                ],
                ['result' => 3],
            ],
        );

        $ranges = $service->getRanges()->values()->all();
        $ipv6Range = collect($ranges)->firstOrFail(fn (DhcpRange $range): bool => $range->type === 'ipv6');

        $this->assertCount(2, $ranges);
        $this->assertSame('18446744073709551616', $ipv6Range->totalAddresses);
        $this->assertSame(1, $ipv6Range->usedAddresses);

        $ipv6Status = $service->getPoolStatus('ipv6');

        $this->assertSame(PHP_INT_MAX, $ipv6Status->total);
        $this->assertSame(1, $ipv6Status->used);
        $this->assertSame(PHP_INT_MAX - 1, $ipv6Status->available);

        $ipv4Status = $service->getPoolStatus('ipv4');

        $this->assertSame(5, $ipv4Status->total);
        $this->assertSame(1, $ipv4Status->used);
        $this->assertSame(4, $ipv4Status->available);
        $this->assertSame(0.2, $ipv4Status->utilisation);
    }

    public function test_get_pool_status_aggregates_ipv4_and_ipv6_ranges(): void
    {
        $service = $this->dualStackService();

        $this->fakeDualStackConfigGet(
            [
                'Dhcp4' => [
                    'subnet4' => [
                        ['subnet' => '10.0.0.0/24', 'pools' => [['pool' => '10.0.0.10 - 10.0.0.14']]],
                    ],
                ],
            ],
            [
                'Dhcp6' => [
                    'subnet6' => [
                        ['subnet' => '2001:db8::/64', 'pools' => [['pool' => '2001:db8::10 - 2001:db8::14']]],
                    ],
                ],
            ],
            ipv4LeaseResponses: [
                [
                    'result' => 0,
                    'arguments' => [
                        'leases' => [$this->keaLease('10.0.0.11', 'AA:BB:CC:00:00:01', 'v4-host')],
                    ],
                ],
                ['result' => 3],
            ],
            ipv6LeaseResponses: [
                [
                    'result' => 0,
                    'arguments' => [
                        'leases' => [$this->keaLease6('2001:db8::11', hwAddress: 'AA:BB:CC:00:00:02')],
                    ],
                ],
                ['result' => 3],
            ],
        );

        $ipv4Status = $service->getPoolStatus('ipv4');

        $this->assertSame(5, $ipv4Status->total);
        $this->assertSame(1, $ipv4Status->used);
        $this->assertSame(4, $ipv4Status->available);
        $this->assertSame(0.2, $ipv4Status->utilisation);

        $ipv6Status = $service->getPoolStatus('ipv6');

        $this->assertSame(5, $ipv6Status->total);
        $this->assertSame(1, $ipv6Status->used);
        $this->assertSame(4, $ipv6Status->available);
        $this->assertSame(0.2, $ipv6Status->utilisation);
    }

    public function test_get_pool_status_default_family_matches_explicit_ipv4(): void
    {
        $service = $this->dualStackService();

        $this->fakeDualStackConfigGet(
            [
                'Dhcp4' => [
                    'subnet4' => [
                        ['subnet' => '10.0.0.0/24', 'pools' => [['pool' => '10.0.0.10 - 10.0.0.14']]],
                    ],
                ],
            ],
            [
                'Dhcp6' => [
                    'subnet6' => [
                        ['subnet' => '2001:db8::/64', 'pools' => [['pool' => '2001:db8::10 - 2001:db8::14']]],
                    ],
                ],
            ],
            ipv4LeaseResponses: [
                [
                    'result' => 0,
                    'arguments' => [
                        'leases' => [$this->keaLease('10.0.0.11', 'AA:BB:CC:00:00:01', 'v4-host')],
                    ],
                ],
                ['result' => 3],
            ],
            ipv6LeaseResponses: [
                [
                    'result' => 0,
                    'arguments' => [
                        'leases' => [$this->keaLease6('2001:db8::11', hwAddress: 'AA:BB:CC:00:00:02')],
                    ],
                ],
                ['result' => 3],
            ],
        );

        $this->assertEquals($service->getPoolStatus('ipv4'), $service->getPoolStatus());
    }

    public function test_ipv6_config_get_failure_returns_empty_ipv6_ranges_without_breaking_ipv4(): void
    {
        $service = $this->dualStackService();

        Http::fake([
            'kea4.local' => function ($request) {
                if (($request->data()['command'] ?? null) === 'config-get') {
                    return Http::response([[
                        'result' => 0,
                        'arguments' => [
                            'Dhcp4' => [
                                'subnet4' => [
                                    ['subnet' => '10.0.0.0/24', 'pools' => [['pool' => '10.0.0.10 - 10.0.0.14']]],
                                ],
                            ],
                        ],
                    ]]);
                }

                return Http::response([['result' => 3]]);
            },
            'kea6.local' => function ($request) {
                if (($request->data()['command'] ?? null) === 'config-get') {
                    return Http::response('Unauthorized', 401);
                }

                return Http::response([['result' => 3]]);
            },
        ]);

        $ranges = $service->getRanges()->values()->all();
        $status = $service->getFetchStatus();

        $this->assertCount(1, $ranges);
        $this->assertSame('ipv4', $ranges[0]->type);
        $this->assertFalse($status['ipv6_ranges']);
        $this->assertTrue($status['ipv4_ranges']);
    }

    public function test_ipv6_ranges_config_get_failure_does_not_corrupt_already_fetched_ipv6_lease_status(): void
    {
        $service = $this->dualStackService();

        $leasePageQueue = [[
            'result' => 0,
            'arguments' => ['leases' => [$this->keaLease6('2001:db8::1', duid: '00:01:00:01:00:00:00:00:aa:bb:cc:00:00:01')]],
        ]];

        Http::fake([
            'kea4.local' => Http::response([['result' => 3]]),
            'kea6.local' => function ($request) use (&$leasePageQueue) {
                $command = $request->data()['command'] ?? null;

                if ($command === 'config-get') {
                    return Http::response('Unauthorized', 401);
                }

                if ($command === 'lease6-get-page') {
                    return Http::response([array_shift($leasePageQueue) ?? ['result' => 3]]);
                }

                return Http::response([['result' => 3]]);
            },
        ]);

        $leases = $service->getLeases();
        $ranges = $service->getRanges();
        $status = $service->getFetchStatus();

        $this->assertSame(['2001:db8::1'], $leases->pluck('ip')->all());
        $this->assertTrue($ranges->isEmpty());
        $this->assertTrue($status['ipv6']);
        $this->assertFalse($status['ipv6_ranges']);
        $this->assertTrue($status['ipv4']);
        $this->assertTrue($status['ipv4_ranges']);
    }

    public function test_ipv6_ranges_skipped_when_ipv6_client_not_configured(): void
    {
        Http::fake([
            'kea.local' => function ($request) {
                if (($request->data()['command'] ?? null) === 'config-get') {
                    return Http::response([[
                        'result' => 0,
                        'arguments' => [
                            'Dhcp4' => [
                                'subnet4' => [
                                    ['subnet' => '10.0.0.0/24', 'pools' => [['pool' => '10.0.0.10 - 10.0.0.14']]],
                                ],
                            ],
                        ],
                    ]]);
                }

                return Http::response([['result' => 3]]);
            },
        ]);

        $ranges = $this->service->getRanges()->values()->all();

        $this->assertCount(1, $ranges);
        $this->assertSame('ipv4', $ranges[0]->type);
    }

    public function test_ipv6_ranges_skipped_when_ipv6_lease_fetch_failed(): void
    {
        $service = $this->dualStackService();

        Http::fake([
            'kea4.local' => function ($request) {
                if (($request->data()['command'] ?? null) === 'config-get') {
                    return Http::response([[
                        'result' => 0,
                        'arguments' => [
                            'Dhcp4' => [
                                'subnet4' => [
                                    ['subnet' => '10.0.0.0/24', 'pools' => [['pool' => '10.0.0.10 - 10.0.0.14']]],
                                ],
                            ],
                        ],
                    ]]);
                }

                return Http::response([['result' => 3]]);
            },
            'kea6.local' => Http::response('Unauthorized', 401),
        ]);

        $ranges = $service->getRanges()->values()->all();

        $this->assertCount(1, $ranges);
        $this->assertSame('ipv4', $ranges[0]->type);

        Http::assertNotSent(function ($request): bool {
            return $request->url() === 'https://kea6.local'
                && ($request->data()['command'] ?? null) === 'config-get';
        });
    }
}
