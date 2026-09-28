<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\IpAddress;
use App\Models\Role;
use App\Models\Setting;
use App\Models\SwitchConfig;
use App\Models\User;
use App\Services\Interfaces\IpBandwidthInterface;
use App\Services\LibreNms\LibreNmsService;
use App\Services\ValueObjects\IpBandwidthResult;
use App\Services\ValueObjects\PortDetail;
use App\Services\ValueObjects\ResolvedPort;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IpAddressControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function createAdminUser(): User
    {
        $user = User::factory()->create();
        $role = new Role;
        $role->code = 'admin';
        $role->name = 'Admin';
        $role->save();
        $user->roles()->attach($role);

        return $user;
    }

    public function test_admin_can_view_ip_index(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $ip = new IpAddress;
        $ip->address = '10.0.0.1';
        $ip->last_seen_at = Carbon::now();
        $ip->save();

        $response = $this->actingAs($admin)->get('/admin/ips');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Ips/Index')
            ->has('ips')
        );
    }

    public function test_admin_can_view_ip_detail(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $ip = new IpAddress;
        $ip->address = '10.0.0.2';
        $ip->last_seen_at = Carbon::now();
        $ip->save();

        $response = $this->actingAs($admin)->get('/admin/ips/'.$ip->address);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Ips/Show')
            ->has('ip')
        );
    }

    public function test_ip_show_route_uses_ip_address_parameter(): void
    {
        $ip = IpAddress::factory()->create(['address' => '10.0.0.222']);

        $this->assertSame('/admin/ips/10.0.0.222', route('admin.ips.show', ['ip' => $ip], false));
    }

    public function test_admin_can_create_ip(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/admin/ips/create');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Admin/Ips/Create'));
    }

    public function test_non_admin_cannot_view_ips(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/admin/ips');

        $response->assertForbidden();
    }

    public function test_ip_index_sorts_addresses_numerically(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        foreach (['2001:db8::10', '10.0.0.10', '2001:db8::2', '10.0.0.2', '::1', '10.0.0.1'] as $address) {
            $ip = new IpAddress;
            $ip->address = $address;
            $ip->last_seen_at = Carbon::now();
            $ip->save();
        }

        $asc = ['::1', '10.0.0.1', '10.0.0.2', '10.0.0.10', '2001:db8::2', '2001:db8::10'];

        $listed = fn (string $direction): array => array_column(
            $this->actingAs($admin)->get('/admin/ips?order=address&direction='.$direction)
                ->viewData('page')['props']['ips']['data'],
            'address',
        );

        $this->assertSame($asc, $listed('asc'));
        $this->assertSame(array_reverse($asc), $listed('desc'));
    }

    public function test_admin_can_filter_ips_by_address(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $ip1 = new IpAddress;
        $ip1->address = '10.0.0.1';
        $ip1->last_seen_at = Carbon::now();
        $ip1->save();

        $ip2 = new IpAddress;
        $ip2->address = '192.168.1.1';
        $ip2->last_seen_at = Carbon::now();
        $ip2->save();

        $response = $this->actingAs($admin)->get('/admin/ips?address=10.0.0.1');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->has('ips.data', 1));
    }

    public function test_admin_can_filter_ips_by_partial_address(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $ip1 = new IpAddress;
        $ip1->address = '10.0.0.1';
        $ip1->last_seen_at = Carbon::now();
        $ip1->save();

        $ip2 = new IpAddress;
        $ip2->address = '10.0.0.2';
        $ip2->last_seen_at = Carbon::now();
        $ip2->save();

        $ip3 = new IpAddress;
        $ip3->address = '192.168.1.1';
        $ip3->last_seen_at = Carbon::now();
        $ip3->save();

        $response = $this->actingAs($admin)->get('/admin/ips?address=10.0.0');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->has('ips.data', 2));
    }

    public function test_admin_can_filter_ips_by_status(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $allowed = new IpAddress;
        $allowed->address = '10.0.0.1';
        $allowed->internet_enabled = true;
        $allowed->last_seen_at = Carbon::now();
        $allowed->save();

        $blocked = new IpAddress;
        $blocked->address = '10.0.0.2';
        $blocked->internet_enabled = false;
        $blocked->last_seen_at = Carbon::now();
        $blocked->save();

        $unassigned = new IpAddress;
        $unassigned->address = '10.0.0.3';
        $unassigned->internet_enabled = null;
        $unassigned->last_seen_at = Carbon::now();
        $unassigned->save();

        $allowedResponse = $this->actingAs($admin)->get('/admin/ips?status=allowed');
        $allowedResponse->assertOk();
        $allowedResponse->assertInertia(fn ($page) => $page
            ->has('ips.data', 1)
            ->where('ips.data.0.address', '10.0.0.1')
            ->where('filters.status', 'allowed')
        );

        $blockedResponse = $this->actingAs($admin)->get('/admin/ips?status=blocked');
        $blockedResponse->assertOk();
        $blockedResponse->assertInertia(fn ($page) => $page
            ->has('ips.data', 1)
            ->where('ips.data.0.address', '10.0.0.2')
        );

        $unassignedResponse = $this->actingAs($admin)->get('/admin/ips?status=unassigned');
        $unassignedResponse->assertOk();
        $unassignedResponse->assertInertia(fn ($page) => $page
            ->has('ips.data', 1)
            ->where('ips.data.0.address', '10.0.0.3')
            ->where('ips.data.0.internet_enabled', null)
        );
    }

    public function test_discovered_ip_defaults_to_null_internet_enabled(): void
    {
        $ip = new IpAddress;
        $ip->address = '10.1.2.3';
        $ip->last_seen_at = Carbon::now();
        $ip->save();

        $this->assertNull($ip->fresh()->internet_enabled);
    }

    public function test_admin_can_filter_ips_by_ipv6_address_case_insensitively(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $ip1 = new IpAddress;
        $ip1->address = '2001:db8::1';
        $ip1->last_seen_at = Carbon::now();
        $ip1->save();

        $ip2 = new IpAddress;
        $ip2->address = '10.0.0.1';
        $ip2->last_seen_at = Carbon::now();
        $ip2->save();

        $response = $this->actingAs($admin)->get('/admin/ips?address='.urlencode('2001:DB8::1'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->has('ips.data', 1));
    }

    public function test_admin_can_filter_ips_by_nickname(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $ip = new IpAddress;
        $ip->address = '10.0.0.5';
        $ip->last_seen_at = Carbon::now();
        $ip->save();

        $user = User::factory()->create(['nickname' => 'TargetUser']);
        $user->addIp('10.0.0.5');

        $response = $this->actingAs($admin)->get('/admin/ips?nickname=TargetUser');

        $response->assertOk();
    }

    #[DataProvider('ipSortingProvider')]
    public function test_admin_can_sort_ips(string $queryString): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $ip = new IpAddress;
        $ip->address = '10.0.0.10';
        $ip->last_seen_at = Carbon::now();
        $ip->save();

        $response = $this->actingAs($admin)->get('/admin/ips?'.$queryString);
        $response->assertOk();
    }

    public static function ipSortingProvider(): array
    {
        return [
            'by last_seen_at' => ['order=last_seen_at'],
            'by address descending' => ['order=address&direction=desc'],
        ];
    }

    public function test_admin_can_view_ip_show_without_port(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $ip = new IpAddress;
        $ip->address = '10.0.0.20';
        $ip->last_seen_at = Carbon::now();
        $ip->save();

        $response = $this->actingAs($admin)->get('/admin/ips/'.$ip->address);
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Ips/Show')
            ->where('port', null)
            ->where('switchInfo', null)
        );
    }

    #[DataProvider('toggleActionsProvider')]
    public function test_admin_can_toggle_ip_setting(string $pathSuffix, string $field, int $value): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $ip = new IpAddress;
        $ip->address = '10.0.0.34';
        $ip->last_seen_at = Carbon::now();
        $ip->save();

        $response = $this->actingAs($admin)->post('/admin/ips/'.$ip->address.$pathSuffix, [
            $field => $value,
        ]);
        $response->assertRedirect(route('admin.ips.show', ['ip' => $ip], false));
    }

    public static function toggleActionsProvider(): array
    {
        return [
            'rate limit enabled' => ['/limit', 'limit', 1],
            'rate limit disabled' => ['/limit', 'limit', 0],
            'internet allowed' => ['/internet', 'allow', 1],
            'internet denied' => ['/internet', 'allow', 0],
        ];
    }

    #[DataProvider('toggleEndpointsProvider')]
    public function test_toggle_endpoint_requires_its_field(string $pathSuffix, string $field): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $ip = new IpAddress;
        $ip->address = '10.0.0.52';
        $ip->last_seen_at = Carbon::now();
        $ip->save();

        $response = $this->actingAs($admin)->post('/admin/ips/'.$ip->address.$pathSuffix, []);
        $response->assertSessionHasErrors([$field]);
    }

    #[DataProvider('toggleEndpointsProvider')]
    public function test_toggle_endpoint_rejects_non_boolean_value(string $pathSuffix, string $field): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $ip = new IpAddress;
        $ip->address = '10.0.0.53';
        $ip->last_seen_at = Carbon::now();
        $ip->save();

        $response = $this->actingAs($admin)->post('/admin/ips/'.$ip->address.$pathSuffix, [
            $field => 'notabool',
        ]);
        $response->assertSessionHasErrors([$field]);
    }

    public static function toggleEndpointsProvider(): array
    {
        return [
            'rate limit' => ['/limit', 'limit'],
            'internet' => ['/internet', 'allow'],
            'dns filter' => ['/dns-filter', 'filter'],
        ];
    }

    public function test_admin_can_store_new_ip(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->post('/admin/ips', [
            'address' => '10.0.0.100',
            'comment' => 'Test IP',
            'allow' => false,
            'limit' => false,
        ]);
        $response->assertRedirect('/admin/ips/10.0.0.100');
        $this->assertDatabaseHas('ip_addresses', ['address' => '10.0.0.100']);
    }

    public function test_admin_can_store_new_ip_with_allow_and_limit(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->post('/admin/ips', [
            'address' => '10.0.0.101',
            'comment' => 'Test IP Allowed',
            'allow' => true,
            'limit' => true,
        ]);
        $response->assertRedirect('/admin/ips/10.0.0.101');
        $this->assertDatabaseHas('ip_addresses', ['address' => '10.0.0.101']);
    }

    #[DataProvider('resolvedPortShowProvider')]
    public function test_admin_can_view_ip_show_with_port_data(string $ipAddress, string $mac): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $inventory = Mockery::mock(LibreNmsService::class);
        $inventory->shouldReceive('resolveIpToPort')
            ->andReturn(new ResolvedPort(ip: $ipAddress, mac: $mac, port: '1', switch: ''));
        $inventory->shouldReceive('getPortDetail')
            ->andReturn(new PortDetail(hostname: 'switch01', interface: 'Gi0/1', status: 'up', adminStatus: 'down', speed: 1000));
        $this->app->instance(LibreNmsService::class, $inventory);

        $ip = new IpAddress;
        $ip->address = $ipAddress;
        $ip->last_seen_at = Carbon::now();
        $ip->save();

        SwitchConfig::factory()->create(['hostname' => 'switch01']);

        $response = $this->actingAs($admin)->get('/admin/ips/'.$ip->address);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Ips/Show')
            ->has('ip')
            ->has('port')
            ->has('switchInfo')
            ->where('switchInfo.switchName', 'switch01')
            ->where('switchInfo.portId', 'Gi0/1')
        );
    }

    public static function resolvedPortShowProvider(): array
    {
        return [
            'resolved port data' => ['10.0.0.200', 'AA:BB:CC:DD:EE:FF'],
            'successful switch connection' => ['10.0.0.201', 'AA:BB:CC:DD:EE:01'],
        ];
    }

    public function test_admin_can_view_ip_show_with_fallback_switch_config_when_hostname_not_in_db(): void
    {
        // Covers IpAddressController::resolveSwitchConfig() lines 196-205:
        // when no SwitchConfig record matches the hostname, a new SwitchConfig is built
        // from the aperture.cisco.* config values via SwitchConfig::defaultFallback().
        Queue::fake();
        $admin = $this->createAdminUser();

        config([
            'aperture.cisco.hostname' => 'fallback-switch.local',
            'aperture.cisco.username' => 'fallback-user',
            'aperture.cisco.password' => 'fallback-pass',
            'aperture.cisco.enablePassword' => 'fallback-enable',
            'aperture.cisco.timeout' => 10,
        ]);

        $inventory = Mockery::mock(LibreNmsService::class);
        $inventory->shouldReceive('resolveIpToPort')
            ->andReturn(new ResolvedPort(ip: '10.0.0.99', mac: 'BB:CC:DD:EE:FF:00', port: '1', switch: ''));
        $inventory->shouldReceive('getPortDetail')
            ->andReturn(new PortDetail(
                hostname: 'unknown-switch.local',  // No SwitchConfig for this hostname
                interface: 'Gi0/1',
                status: 'up',
                adminStatus: 'up',
                speed: 1000
            ));
        $this->app->instance(LibreNmsService::class, $inventory);

        $ip = new IpAddress;
        $ip->address = '10.0.0.99';
        $ip->last_seen_at = Carbon::now();
        $ip->save();

        // No SwitchConfig created for 'unknown-switch.local' — fallback will be used

        $response = $this->actingAs($admin)->get('/admin/ips/'.$ip->address);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Ips/Show')
            ->has('ip')
            ->has('port')
            ->has('switchInfo')
            ->where('switchInfo.switchName', 'fallback-switch.local')
            ->where('switchInfo.portId', 'Gi0/1')
        );
    }

    public function test_admin_can_fetch_ip_bandwidth(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $ip = IpAddress::factory()->create(['address' => '10.0.0.1']);

        $this->mock(IpBandwidthInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getIpBandwidth')
                ->with('10.0.0.1', '24h')
                ->once()
                ->andReturn(new IpBandwidthResult(
                    received: 1024000,
                    sent: 512000,
                    timestamps: ['1700000000'],
                    download: [8192.0],
                    upload: [4096.0],
                ));
        });

        $response = $this->actingAs($admin)->getJson('/admin/ips/'.$ip->address.'/bandwidth');

        $response->assertOk()
            ->assertJsonStructure(['timestamps', 'download', 'upload', 'totalReceived', 'totalSent']);
    }

    #[DataProvider('bandwidthRangeProvider')]
    public function test_admin_bandwidth_accepts_range_parameter(string $range): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $ip = IpAddress::factory()->create(['address' => '10.0.0.1']);

        $this->mock(IpBandwidthInterface::class, function (MockInterface $mock) use ($range): void {
            $mock->shouldReceive('getIpBandwidth')
                ->withArgs(fn (string $ipAddr, string $rangeArg): bool => $rangeArg === $range)
                ->once()
                ->andReturn(new IpBandwidthResult(
                    received: 0,
                    sent: 0,
                    timestamps: [],
                    download: [],
                    upload: [],
                ));
        });

        $response = $this->actingAs($admin)->getJson('/admin/ips/'.$ip->address.'/bandwidth?range='.$range);

        $response->assertOk();
    }

    public static function bandwidthRangeProvider(): array
    {
        return [
            '4 days' => ['4d'],
            '7 days' => ['7d'],
        ];
    }

    public function test_admin_bandwidth_rejects_72h_range(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $ip = IpAddress::factory()->create(['address' => '10.0.0.1']);

        $response = $this->actingAs($admin)->getJson('/admin/ips/'.$ip->address.'/bandwidth?range=72h');

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['range']);
    }

    public function test_non_admin_cannot_fetch_ip_bandwidth(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $ip = IpAddress::factory()->create();

        $this->actingAs($user)->getJson('/admin/ips/'.$ip->address.'/bandwidth')
            ->assertForbidden();
    }

    #[DataProvider('dnsFilterTogglesProvider')]
    public function test_admin_can_toggle_dns_filter(bool $initial, int $filterValue, bool $expected): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $ip = IpAddress::factory()->create(['dns_filtering_enabled' => $initial]);

        $response = $this->actingAs($admin)->post('/admin/ips/'.$ip->address.'/dns-filter', [
            'filter' => $filterValue,
        ]);
        $response->assertRedirect(route('admin.ips.show', ['ip' => $ip], false));
        $this->assertSame($expected, (bool) $ip->fresh()->dns_filtering_enabled);
    }

    public static function dnsFilterTogglesProvider(): array
    {
        return [
            'enable' => [false, 1, true],
            'disable' => [true, 0, false],
        ];
    }

    public function test_non_admin_cannot_toggle_dns_filter(): void
    {
        $user = User::factory()->create();
        $ip = IpAddress::factory()->create();

        $this->actingAs($user)
            ->post('/admin/ips/'.$ip->address.'/dns-filter', ['filter' => 1])
            ->assertForbidden();
    }

    public function test_ip_show_passes_switch_info_when_port_resolved(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $inventory = Mockery::mock(LibreNmsService::class);
        $inventory->shouldReceive('resolveIpToPort')
            ->andReturn(new ResolvedPort(ip: '10.0.0.1', mac: 'AA:BB:CC:DD:EE:FF', port: '1', switch: ''));
        $inventory->shouldReceive('getPortDetail')
            ->andReturn(new PortDetail(hostname: 'switch01', interface: 'Gi0/1', status: 'up', adminStatus: 'up', speed: 1000));
        $this->app->instance(LibreNmsService::class, $inventory);

        $ip = IpAddress::factory()->create();
        $switchConfig = SwitchConfig::factory()->create(['hostname' => 'switch01']);

        $response = $this->actingAs($admin)->get('/admin/ips/'.$ip->address);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Ips/Show')
            ->has('switchInfo')
            ->where('switchInfo.switchId', $switchConfig->id)
            ->where('switchInfo.switchName', 'switch01')
            ->where('switchInfo.portId', 'Gi0/1')
            ->missing('status')
            ->missing('shutdown')
        );
    }

    public function test_ip_show_passes_null_switch_info_when_no_port(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $inventory = Mockery::mock(LibreNmsService::class);
        $inventory->shouldReceive('resolveIpToPort')->andReturn(null);
        $this->app->instance(LibreNmsService::class, $inventory);

        $ip = IpAddress::factory()->create();

        $response = $this->actingAs($admin)->get('/admin/ips/'.$ip->address);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Ips/Show')
            ->where('switchInfo', null)
            ->missing('status')
            ->missing('shutdown')
        );
    }

    #[DataProvider('toggleAuditLogProvider')]
    public function test_toggle_action_creates_audit_log(string $pathSuffix, array $payload, string $initialColumn, string $expectedAction): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $ip = IpAddress::factory()->create([$initialColumn => false]);

        $this->actingAs($admin)->post('/admin/ips/'.$ip->address.$pathSuffix, $payload);

        $this->assertDatabaseHas('audit_logs', [
            'action' => $expectedAction,
            'subject_type' => $ip->getMorphClass(),
            'subject_id' => $ip->id,
            'process' => 'admin',
        ]);
        $log = AuditLog::where('action', $expectedAction)->first();
        $this->assertNotNull($log);
        $this->assertTrue($log->metadata['enabled']);
    }

    public static function toggleAuditLogProvider(): array
    {
        return [
            'internet toggle' => ['/internet', ['allow' => 1], 'internet_enabled', 'ip.internet_toggled'],
            'rate limit toggle' => ['/limit', ['limit' => 1], 'rate_limit_enabled', 'ip.rate_limit_toggled'],
            'dns filter toggle' => ['/dns-filter', ['filter' => 1], 'dns_filtering_enabled', 'ip.dns_filter_toggled'],
        ];
    }

    public function test_show_auto_creates_ip_within_managed_range(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        Setting::set('network.managed_ranges_v4', 'Managed IPv4 Ranges', json_encode(['10.0.0.0/24']));

        $this->assertNull(IpAddress::where('address', '10.0.0.99')->first());

        $response = $this->actingAs($admin)->get('/admin/ips/10.0.0.99');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Admin/Ips/Show'));

        $ip = IpAddress::where('address', '10.0.0.99')->first();
        $this->assertNotNull($ip);
        $this->assertNotNull($ip->last_seen_at);
    }

    public function test_show_returns_404_for_ip_outside_managed_range(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        Setting::set('network.managed_ranges_v4', 'Managed IPv4 Ranges', json_encode(['10.0.0.0/24']));

        $response = $this->actingAs($admin)->get('/admin/ips/192.168.1.50');

        $response->assertNotFound();
        $this->assertNull(IpAddress::where('address', '192.168.1.50')->first());
    }
}
