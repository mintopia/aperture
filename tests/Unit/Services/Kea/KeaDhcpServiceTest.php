<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Kea;

use App\Services\Interfaces\DhcpInterface;
use App\Services\Kea\KeaClient;
use App\Services\Kea\KeaDhcpService;
use App\Services\ValueObjects\DhcpLease;
use App\Services\ValueObjects\DhcpPoolStatus;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KeaDhcpServiceTest extends TestCase
{
    private const NOW_TIMESTAMP = 1_700_000_000;

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

    public function test_get_pool_status_returns_zeroed_status(): void
    {
        $status = $this->service->getPoolStatus();

        $this->assertInstanceOf(DhcpPoolStatus::class, $status);
        $this->assertSame(0, $status->total);
        $this->assertSame(0, $status->used);
        $this->assertSame(0, $status->available);
        $this->assertSame(0.0, $status->utilisation);
    }

    public function test_get_leases_returns_empty_collection(): void
    {
        $leases = $this->service->getLeases();

        $this->assertInstanceOf(Collection::class, $leases);
        $this->assertTrue($leases->isEmpty());
    }

    public function test_get_ranges_returns_empty_collection(): void
    {
        $ranges = $this->service->getRanges();

        $this->assertInstanceOf(Collection::class, $ranges);
        $this->assertTrue($ranges->isEmpty());
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
}
