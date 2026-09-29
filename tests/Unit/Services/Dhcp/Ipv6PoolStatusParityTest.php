<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dhcp;

use App\Enums\AddressFamily;
use App\Services\Cisco\CiscoDhcpService;
use App\Services\Interfaces\DhcpInterface;
use App\Services\Interfaces\SwitchCommandTransportInterface;
use App\Services\Kea\KeaClient;
use App\Services\Kea\KeaDhcpService;
use App\Services\NetworkSwitch\IosOutputParser;
use App\Services\OpnSense\OpnSenseClient;
use App\Services\OpnSense\OpnSenseDhcpService;
use App\Services\VyOs\VyOsClient;
use App\Services\VyOs\VyOsDhcpService;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Fake;
use Tests\TestCase;

class Ipv6PoolStatusParityTest extends TestCase
{
    private const LEASE_IP = '2001:db8:0:1::10';

    protected function setUp(): void
    {
        parent::setUp();
        Date::setTestNow(Date::createFromTimestamp(2_000_000_000));
    }

    protected function tearDown(): void
    {
        Date::setTestNow();
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function providers(): array
    {
        return [
            'kea' => ['kea'],
            'opnsense' => ['opnsense'],
            'vyos' => ['vyos'],
            'cisco' => ['cisco'],
        ];
    }

    #[DataProvider('providers')]
    public function test_slash_64_pool_status_is_exact_for_every_provider(string $provider): void
    {
        $status = $this->service($provider)->snapshot()->poolStatus(AddressFamily::IPv6);

        $this->assertSame('18446744073709551616', $status->total);
        $this->assertSame(1, $status->used);
        $this->assertSame('18446744073709551615', $status->available);
        $this->assertSame(0.0, $status->utilisation);
    }

    private function service(string $provider): DhcpInterface
    {
        return match ($provider) {
            'kea' => $this->kea(),
            'opnsense' => $this->opnSense(),
            'vyos' => $this->vyOs(),
            'cisco' => $this->cisco(),
        };
    }

    private function kea(): KeaDhcpService
    {
        $leasePages = [
            ['result' => 0, 'arguments' => ['leases' => [[
                'ip-address' => self::LEASE_IP,
                'hw-address' => 'AA:BB:CC:00:00:02',
                'hostname' => '',
                'state' => 0,
                'cltt' => 1_999_999_999,
                'valid-lft' => 1000,
                'type' => 'IA_NA',
            ]]]],
            ['result' => 3],
        ];

        Http::fake([
            'kea4.local' => Http::response([['result' => 3]]),
            'kea6.local' => function ($request) use (&$leasePages) {
                if (($request->data()['command'] ?? null) === 'config-get') {
                    return Http::response([['result' => 0, 'arguments' => ['Dhcp6' => ['subnet6' => [[
                        'subnet' => '2001:db8:0:1::/64',
                        'pools' => [['pool' => '2001:db8:0:1::/64']],
                    ]]]]]]);
                }

                return Http::response([array_shift($leasePages) ?? ['result' => 3]]);
            },
        ]);

        return new KeaDhcpService(
            new KeaClient(endpoint: 'https://kea4.local'),
            new KeaClient(endpoint: 'https://kea6.local', service: 'dhcp6'),
        );
    }

    private function opnSense(): OpnSenseDhcpService
    {
        Fake::sequence([
            Fake::response(200, [], (string) json_encode(['rows' => [[
                'interface' => 'em0',
                'subnet' => '2001:db8:0:1::/64',
                'range_from' => '2001:db8:0:1::',
                'range_to' => '2001:db8:0:1:ffff:ffff:ffff:ffff',
                'gateway' => '',
                'description' => 'LAN6',
                'prefix' => '',
            ]]])),
            Fake::response(200, [], (string) json_encode(['rows' => [[
                'address' => self::LEASE_IP,
                'mac' => 'aa:bb:cc:dd:ee:ff',
                'hostname' => '',
                'ends' => '2026-04-15 12:00:00',
                'status' => 'active',
            ]]])),
        ]);

        return new OpnSenseDhcpService(
            client: OpnSenseClient::fromConfig(['endpoint' => 'http://opnsense.test', 'key' => 'key', 'secret' => 'secret'])->request(),
            poolSize: 0,
            ipv6RangesPath: '/api/dhcpv6/ranges',
        );
    }

    private function vyOs(): VyOsDhcpService
    {
        $client = Mockery::mock(VyOsClient::class);
        $client->shouldReceive('retrieve')->with(['service', 'dhcp-server', 'shared-network-name'])->andReturn([]);
        $client->shouldReceive('retrieve')->with(['service', 'dhcpv6-server', 'shared-network-name'])->andReturn([
            'shared-network-name' => ['LAN6' => ['subnet' => ['2001:db8:0:1::/64' => ['range' => ['r' => [
                'start' => '2001:db8:0:1::',
                'stop' => '2001:db8:0:1:ffff:ffff:ffff:ffff',
            ]]]]]],
        ]);
        $client->shouldReceive('showText')->with(['dhcp', 'server', 'leases'])->andReturn('');
        $client->shouldReceive('showText')->with(['dhcpv6', 'server', 'leases'])->andReturn($this->vyOsLeaseTable());

        return new VyOsDhcpService($client, 0);
    }

    private function vyOsLeaseTable(): string
    {
        $columns = ['IPv6 address', 'MAC address', 'State', 'Last communication', 'Lease expiration', 'Remaining', 'Pool', 'Hostname', 'Type', 'DUID'];
        $row = [self::LEASE_IP, 'bc:24:11:78:82:5d', 'active', '2026-06-01 11:40:10+00:00', '2026-06-01 13:40:10+00:00', '1:35:02', 'pool', '', 'IA_NA', '00:01:00:01:30:e2:f6:78:bc:24:11:78:82:5d'];
        $widths = array_map(fn (string $c, string $v): int => max(strlen($c), strlen($v)), $columns, $row);
        $pad = fn (array $cells): string => implode('  ', array_map(fn (string $c, int $w): string => str_pad($c, $w), $cells, $widths));

        return implode("\n", [
            $pad($columns),
            implode('  ', array_map(fn (int $w): string => str_repeat('-', $w), $widths)),
            $pad($row),
        ])."\n";
    }

    private function cisco(): CiscoDhcpService
    {
        $transport = Mockery::mock(SwitchCommandTransportInterface::class);
        $transport->shouldReceive('executeMultiple')->andReturn([
            'show ip dhcp binding' => '',
            'show ip dhcp pool' => '',
            'show running-config | section ip dhcp' => '',
            'show ipv6 dhcp binding' => implode("\n", [
                'Client: FE80::1',
                '  DUID: 00030001AABBCCDDEEFF',
                '  IA NA: IA ID 0x00000001, T1 43200, T2 69120',
                '    Address: 2001:DB8:0:1::10',
                '            preferred lifetime 86400, valid lifetime 172800',
                '            expires at Jun 09 2026 12:00 AM (172800 seconds)',
            ]),
            'show ipv6 dhcp pool' => implode("\n", ['DHCPv6 pool: LAN6', '  Address allocation prefix: 2001:DB8:0:1::/64']),
            'show running-config | section ipv6 dhcp pool' => implode("\n", ['ipv6 dhcp pool LAN6', ' address prefix 2001:DB8:0:1::/64', '!']),
        ]);
        $transport->shouldReceive('disconnect');

        return new CiscoDhcpService($transport, new IosOutputParser);
    }
}
