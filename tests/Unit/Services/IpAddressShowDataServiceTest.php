<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\AuditLog;
use App\Models\DhcpLease;
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Models\User;
use App\Services\Interfaces\PortBandwidthInterface;
use App\Services\Interfaces\PortErrorsInterface;
use App\Services\IpAddressShowDataService;
use App\Services\LibreNms\LibreNmsService;
use App\Services\ValueObjects\PortDetail;
use App\Services\ValueObjects\ResolvedPort;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class IpAddressShowDataServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private PortBandwidthInterface&MockInterface $portBandwidth;

    private PortErrorsInterface&MockInterface $portErrors;

    private LibreNmsService&MockInterface $libreNms;

    private IpAddressShowDataService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->portBandwidth = Mockery::mock(PortBandwidthInterface::class);
        $this->portErrors = Mockery::mock(PortErrorsInterface::class);
        $this->libreNms = Mockery::mock(LibreNmsService::class);

        $this->service = new IpAddressShowDataService(
            $this->portBandwidth,
            $this->portErrors,
            $this->libreNms,
        );
    }

    public function test_assemble_returns_expected_keys(): void
    {
        $ip = IpAddress::factory()->create();

        $this->portBandwidth->shouldReceive('isAvailable')->andReturn(false);
        $this->libreNms->shouldReceive('resolveIpToPort')->andReturn(null);

        $result = $this->service->assemble($ip);

        $this->assertArrayHasKey('ip', $result);
        $this->assertArrayHasKey('port', $result);
        $this->assertArrayHasKey('switchInfo', $result);
        $this->assertArrayHasKey('portBandwidth', $result);
        $this->assertArrayHasKey('portErrors', $result);
        $this->assertArrayHasKey('metricsAvailable', $result);
        $this->assertArrayHasKey('users', $result);
        $this->assertArrayHasKey('macAddresses', $result);
        $this->assertArrayHasKey('dhcpLeases', $result);
        $this->assertArrayHasKey('auditLogs', $result);
    }

    public function test_assemble_without_port_returns_null_switch_info(): void
    {
        $ip = IpAddress::factory()->create();

        $this->portBandwidth->shouldReceive('isAvailable')->andReturn(false);
        $this->libreNms->shouldReceive('resolveIpToPort')->andReturn(null);

        $result = $this->service->assemble($ip);

        $this->assertNull($result['port']);
        $this->assertNull($result['switchInfo']);
        $this->assertNull($result['portBandwidth']);
        $this->assertNull($result['portErrors']);
    }

    public function test_assemble_includes_current_mac(): void
    {
        $ip = IpAddress::factory()->create();
        $mac = MacAddress::factory()->create();
        $ip->macAddresses()->attach($mac, ['source' => 'arp', 'last_seen_at' => now()]);

        $this->portBandwidth->shouldReceive('isAvailable')->andReturn(false);
        $this->libreNms->shouldReceive('resolveIpToPort')->andReturn(null);

        $result = $this->service->assemble($ip);

        $this->assertNotNull($result['ip']['current_mac']);
        $this->assertSame($mac->id, $result['ip']['current_mac']['id']);
        $this->assertSame($mac->mac_address, $result['ip']['current_mac']['mac_address']);
    }

    public function test_assemble_includes_mac_addresses(): void
    {
        $ip = IpAddress::factory()->create();
        $mac = MacAddress::factory()->create();
        $ip->macAddresses()->attach($mac, ['source' => 'arp', 'last_seen_at' => now()]);

        $this->portBandwidth->shouldReceive('isAvailable')->andReturn(false);
        $this->libreNms->shouldReceive('resolveIpToPort')->andReturn(null);

        $result = $this->service->assemble($ip);

        $this->assertCount(1, $result['macAddresses']);
        $first = $result['macAddresses']->first();
        $this->assertSame($mac->id, $first['id']);
        $this->assertSame($mac->mac_address, $first['mac_address']);
        $this->assertSame('arp', $first['source']);
    }

    public function test_assemble_includes_dhcp_leases(): void
    {
        $ip = IpAddress::factory()->create();
        $mac = MacAddress::factory()->create();
        $lease = DhcpLease::factory()->create([
            'ip_address_id' => $ip->id,
            'mac_address_id' => $mac->id,
            'hostname' => 'test-host',
        ]);

        $this->portBandwidth->shouldReceive('isAvailable')->andReturn(false);
        $this->libreNms->shouldReceive('resolveIpToPort')->andReturn(null);

        $result = $this->service->assemble($ip);

        $this->assertCount(1, $result['dhcpLeases']);
        $first = $result['dhcpLeases']->first();
        $this->assertSame($lease->id, $first['id']);
        $this->assertSame('test-host', $first['hostname']);
        $this->assertNotNull($first['mac_address']);
    }

    public function test_assemble_includes_audit_logs(): void
    {
        $ip = IpAddress::factory()->create();

        AuditLog::factory()->create([
            'subject_type' => $ip->getMorphClass(),
            'subject_id' => $ip->id,
            'action' => 'ip.internet_toggled',
            'process' => 'admin',
        ]);

        $this->portBandwidth->shouldReceive('isAvailable')->andReturn(false);
        $this->libreNms->shouldReceive('resolveIpToPort')->andReturn(null);

        $result = $this->service->assemble($ip);

        $this->assertCount(1, $result['auditLogs']);
        $first = $result['auditLogs']->first();
        $this->assertSame('ip.internet_toggled', $first['action']);
    }

    public function test_assemble_includes_users(): void
    {
        $ip = IpAddress::factory()->create();
        $user = User::factory()->create();
        $ip->users()->create([
            'user_id' => $user->id,
            'last_seen_at' => now(),
        ]);

        $this->portBandwidth->shouldReceive('isAvailable')->andReturn(false);
        $this->libreNms->shouldReceive('resolveIpToPort')->andReturn(null);

        $result = $this->service->assemble($ip);

        $this->assertCount(1, $result['users']);
    }

    public function test_assemble_metrics_not_available(): void
    {
        $ip = IpAddress::factory()->create();

        $this->portBandwidth->shouldReceive('isAvailable')->andReturn(false);
        $this->libreNms->shouldReceive('resolveIpToPort')->andReturn(null);

        $result = $this->service->assemble($ip);

        $this->assertFalse($result['metricsAvailable']);
    }

    public function test_sum_series_calculates_total_bytes(): void
    {
        $series = [
            ['timestamp' => 0.0, 'value' => 100.0],
            ['timestamp' => 10.0, 'value' => 200.0],
        ];

        $total = $this->service->sumSeries($series);

        // avgRate = (100 + 200) / 2 = 150, dt = 10, total = 150 * 10 / 8 = 187
        $this->assertSame(187, $total);
    }

    public function test_sum_series_empty_returns_zero(): void
    {
        $this->assertSame(0, $this->service->sumSeries([]));
    }

    public function test_sum_series_single_point_returns_zero(): void
    {
        $series = [['timestamp' => 0.0, 'value' => 100.0]];
        $this->assertSame(0, $this->service->sumSeries($series));
    }

    public function test_resolve_port_info_returns_null_on_failure(): void
    {
        $ip = IpAddress::factory()->create();

        $this->libreNms->shouldReceive('resolveIpToPort')
            ->andThrow(new \RuntimeException('Connection failed'));

        $result = $this->service->resolvePortInfo($ip);

        $this->assertNull($result);
    }

    public function test_resolve_port_info_returns_null_when_not_resolved(): void
    {
        $ip = IpAddress::factory()->create();

        $this->libreNms->shouldReceive('resolveIpToPort')->andReturn(null);

        $result = $this->service->resolvePortInfo($ip);

        $this->assertNull($result);
    }

    public function test_resolve_port_info_returns_port_detail(): void
    {
        $ip = IpAddress::factory()->create();

        $port = new PortDetail(
            hostname: 'switch1.example.com',
            interface: 'GigabitEthernet0/1',
            status: 'up',
            adminStatus: 'up',
            speed: 1000,
        );
        $resolved = new ResolvedPort(
            ip: '10.0.0.1',
            mac: 'AA:BB:CC:DD:EE:FF',
            port: '42',
            switch: 'switch1.example.com',
        );

        $this->libreNms->shouldReceive('resolveIpToPort')->andReturn($resolved);
        $this->libreNms->shouldReceive('getPortDetail')->with('42')->andReturn($port);

        $result = $this->service->resolvePortInfo($ip);

        $this->assertInstanceOf(PortDetail::class, $result);
        $this->assertSame('GigabitEthernet0/1', $result->interface);
    }

    public function test_assemble_with_null_current_mac(): void
    {
        $ip = IpAddress::factory()->create();

        $this->portBandwidth->shouldReceive('isAvailable')->andReturn(false);
        $this->libreNms->shouldReceive('resolveIpToPort')->andReturn(null);

        $result = $this->service->assemble($ip);

        $this->assertNull($result['ip']['current_mac']);
    }

    public function test_assemble_mac_address_with_user(): void
    {
        $ip = IpAddress::factory()->create();
        $user = User::factory()->create();
        $mac = MacAddress::factory()->create(['user_id' => $user->id]);
        $ip->macAddresses()->attach($mac, ['source' => 'arp', 'last_seen_at' => now()]);

        $this->portBandwidth->shouldReceive('isAvailable')->andReturn(false);
        $this->libreNms->shouldReceive('resolveIpToPort')->andReturn(null);

        $result = $this->service->assemble($ip);

        $first = $result['macAddresses']->first();
        $this->assertNotNull($first['user']);
        $this->assertSame($user->id, $first['user']['id']);
        $this->assertSame($user->nickname, $first['user']['nickname']);
    }

    public function test_assemble_dhcp_lease_with_mac(): void
    {
        $ip = IpAddress::factory()->create();
        $mac = MacAddress::factory()->create();
        DhcpLease::factory()->create([
            'ip_address_id' => $ip->id,
            'mac_address_id' => $mac->id,
            'hostname' => 'test-lease',
        ]);

        $this->portBandwidth->shouldReceive('isAvailable')->andReturn(false);
        $this->libreNms->shouldReceive('resolveIpToPort')->andReturn(null);

        $result = $this->service->assemble($ip);

        $first = $result['dhcpLeases']->first();
        $this->assertNotNull($first['mac_address']);
        $this->assertSame($mac->id, $first['mac_address']['id']);
    }
}
