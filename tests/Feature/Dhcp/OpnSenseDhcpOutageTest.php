<?php

declare(strict_types=1);

namespace Tests\Feature\Dhcp;

use App\Jobs\SyncDhcpData;
use App\Models\CapabilityAssignment;
use App\Models\DhcpLease;
use App\Models\DhcpPoolStatusRecord;
use App\Models\DhcpRangeRecord;
use App\Models\DhcpSyncState;
use App\Services\Interfaces\DhcpInterface;
use App\Services\OpnSense\OpnSenseDhcpService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

class OpnSenseDhcpOutageTest extends TestCase
{
    use LazilyRefreshDatabase;

    private string $mode = 'up';

    /**
     * @return array<string, array{string}>
     */
    public static function outageModes(): array
    {
        return [
            'connection refused' => ['refuse'],
            'http 500' => ['http500'],
            'garbage body' => ['garbage'],
        ];
    }

    private function respond(RequestInterface $request): mixed
    {
        if ($this->mode === 'refuse') {
            return Create::rejectionFor(new ConnectException('refused', $request));
        }

        if ($this->mode === 'http500') {
            return Create::promiseFor(new Response(500, [], 'boom'));
        }

        if ($this->mode === 'garbage') {
            return Create::promiseFor(new Response(200, [], '<html>maintenance</html>'));
        }

        $rows = match ($request->getUri()->getPath()) {
            '/leases' => [
                ['address' => '10.0.0.10', 'hwaddr' => 'aa:bb:cc:dd:ee:01', 'hostname' => 'a', 'expire' => '2030-01-01 00:00:00', 'state' => 'active'],
                ['address' => '10.0.0.11', 'hwaddr' => 'aa:bb:cc:dd:ee:02', 'hostname' => 'b', 'expire' => '2030-01-01 00:00:00', 'state' => 'active'],
                ['address' => '2001:db8::5', 'hwaddr' => 'aa:bb:cc:dd:ee:03', 'hostname' => 'c', 'expire' => '2030-01-01 00:00:00', 'state' => 'active'],
            ],
            '/v4ranges' => [
                ['interface' => 'lan', 'subnet' => '10.0.0.0/24', 'range_from' => '10.0.0.10', 'range_to' => '10.0.0.100', 'gateway' => '10.0.0.1', 'description' => 'LAN', 'prefix' => ''],
            ],
            default => [
                ['interface' => 'lan', 'subnet' => '2001:db8::/64', 'range_from' => '2001:db8::1', 'range_to' => '2001:db8::ffff', 'gateway' => '', 'description' => 'LAN6', 'prefix' => ''],
            ],
        };

        return Create::promiseFor(new Response(200, [], (string) json_encode(['rows' => $rows, 'total' => count($rows)])));
    }

    private function bindService(): void
    {
        CapabilityAssignment::assign('dhcp', 'opnsense');

        $client = new Client(['handler' => HandlerStack::create(fn (RequestInterface $request, array $options): mixed => $this->respond($request))]);

        $this->app->instance(DhcpInterface::class, new OpnSenseDhcpService(
            client: $client,
            poolSize: 10,
            leasesPath: '/leases',
            ipv4RangesPath: '/v4ranges',
            ipv6RangesPath: '/v6ranges',
            leaseFieldMap: ['ip' => 'address', 'mac' => 'hwaddr', 'hostname' => 'hostname', 'expires' => 'expire', 'status' => 'state'],
            leasesUsePost: true,
        ));
    }

    private function sync(): void
    {
        app()->call([new SyncDhcpData, 'handle']);
    }

    #[DataProvider('outageModes')]
    public function test_repeated_outages_never_delete_stored_data(string $outageMode): void
    {
        $this->bindService();
        $this->sync();

        $this->assertSame(3, DhcpLease::count());
        $this->assertSame(2, DhcpRangeRecord::count());
        $pool = DhcpPoolStatusRecord::where('address_family', 'ipv4')->firstOrFail();
        $used = $pool->used;

        $this->mode = $outageMode;
        for ($i = 0; $i < 4; $i++) {
            $this->sync();
        }

        $this->assertSame(3, DhcpLease::count());
        $this->assertSame(2, DhcpRangeRecord::count());
        $this->assertSame($used, DhcpPoolStatusRecord::where('address_family', 'ipv4')->firstOrFail()->used);
        $this->assertSame(0, DhcpSyncState::where('empty_count', '>', 0)->count());
    }

    public function test_range_endpoint_outage_only_preserves_that_family(): void
    {
        $this->bindService();
        $this->sync();

        $handler = HandlerStack::create(function (RequestInterface $request): mixed {
            return $request->getUri()->getPath() === '/v6ranges'
                ? Create::rejectionFor(new ConnectException('refused', $request))
                : $this->respond($request);
        });
        $this->app->instance(DhcpInterface::class, new OpnSenseDhcpService(
            client: new Client(['handler' => $handler]),
            poolSize: 10,
            leasesPath: '/leases',
            ipv4RangesPath: '/v4ranges',
            ipv6RangesPath: '/v6ranges',
            leaseFieldMap: ['ip' => 'address', 'mac' => 'hwaddr', 'hostname' => 'hostname', 'expires' => 'expire', 'status' => 'state'],
            leasesUsePost: true,
        ));

        for ($i = 0; $i < 4; $i++) {
            $this->sync();
        }

        $this->assertSame(2, DhcpRangeRecord::count());
        $this->assertSame(0, DhcpSyncState::where('empty_count', '>', 0)->count());
    }
}
