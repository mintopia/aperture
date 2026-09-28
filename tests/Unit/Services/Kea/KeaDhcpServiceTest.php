<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Kea;

use App\Services\Interfaces\DhcpInterface;
use App\Services\Kea\KeaClient;
use App\Services\Kea\KeaDhcpService;
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
    private KeaDhcpService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new KeaDhcpService(new KeaClient(endpoint: 'https://kea.local'));
        Carbon::setTestNow(Carbon::createFromTimestamp(2_000_000_000));
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

    public function test_get_pool_status_returns_zeroed_status(): void
    {
        $status = $this->service->getPoolStatus();

        $this->assertInstanceOf(DhcpPoolStatus::class, $status);
        $this->assertSame(0, $status->total);
        $this->assertSame(0, $status->used);
        $this->assertSame(0, $status->available);
        $this->assertSame(0.0, $status->utilisation);
    }

    public function test_get_lease_returns_null(): void
    {
        $this->assertNull($this->service->getLease('10.0.0.1'));
    }

    public function test_get_ranges_returns_empty_collection(): void
    {
        $ranges = $this->service->getRanges();

        $this->assertInstanceOf(Collection::class, $ranges);
        $this->assertTrue($ranges->isEmpty());
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
