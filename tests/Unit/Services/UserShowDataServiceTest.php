<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\AuditLog;
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Models\Role;
use App\Models\SwitchConfig;
use App\Models\SwitchPort;
use App\Models\User;
use App\Models\UserIpAddress;
use App\Services\UserShowDataService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class UserShowDataServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private UserShowDataService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->service = new UserShowDataService;
    }

    protected function linkIpToUser(User $user, IpAddress $ip): UserIpAddress
    {
        $userIp = new UserIpAddress;
        $userIp->user()->associate($user);
        $userIp->ip()->associate($ip);
        $userIp->last_seen_at = now();
        $userIp->save();

        return $userIp;
    }

    public function test_assemble_returns_expected_keys(): void
    {
        $user = User::factory()->create();

        $result = $this->service->assemble($user);

        $this->assertArrayHasKey('user', $result);
        $this->assertArrayHasKey('roles', $result);
        $this->assertArrayHasKey('networkDevices', $result);
        $this->assertArrayHasKey('allInternetEnabled', $result);
        $this->assertArrayHasKey('allRateLimited', $result);
        $this->assertArrayHasKey('ipCount', $result);
        $this->assertArrayHasKey('auditLogs', $result);
    }

    public function test_assemble_returns_user_model(): void
    {
        $user = User::factory()->create();

        $result = $this->service->assemble($user);

        $this->assertSame($user->id, $result['user']->id);
    }

    public function test_assemble_returns_roles(): void
    {
        $user = User::factory()->create();
        $role = new Role;
        $role->code = 'admin';
        $role->name = 'Admin';
        $role->save();
        $user->roles()->attach($role);

        $result = $this->service->assemble($user);

        $this->assertCount(1, $result['roles']);
        $this->assertSame('admin', $result['roles']->first()->code);
    }

    public function test_assemble_all_internet_enabled_when_all_ips_enabled(): void
    {
        $user = User::factory()->create();
        $ip1 = IpAddress::factory()->create(['internet_enabled' => true]);
        $ip2 = IpAddress::factory()->create(['internet_enabled' => true]);
        $this->linkIpToUser($user, $ip1);
        $this->linkIpToUser($user, $ip2);

        $result = $this->service->assemble($user);

        $this->assertTrue($result['allInternetEnabled']);
    }

    public function test_assemble_all_internet_enabled_false_when_one_disabled(): void
    {
        $user = User::factory()->create();
        $ip1 = IpAddress::factory()->create(['internet_enabled' => true]);
        $ip2 = IpAddress::factory()->create(['internet_enabled' => false]);
        $this->linkIpToUser($user, $ip1);
        $this->linkIpToUser($user, $ip2);

        $result = $this->service->assemble($user);

        $this->assertFalse($result['allInternetEnabled']);
    }

    public function test_assemble_all_internet_enabled_false_when_no_ips(): void
    {
        $user = User::factory()->create();

        $result = $this->service->assemble($user);

        $this->assertFalse($result['allInternetEnabled']);
    }

    public function test_assemble_all_rate_limited_when_all_ips_limited(): void
    {
        $user = User::factory()->create();
        $ip1 = IpAddress::factory()->create(['rate_limit_enabled' => true]);
        $ip2 = IpAddress::factory()->create(['rate_limit_enabled' => true]);
        $this->linkIpToUser($user, $ip1);
        $this->linkIpToUser($user, $ip2);

        $result = $this->service->assemble($user);

        $this->assertTrue($result['allRateLimited']);
    }

    public function test_assemble_all_rate_limited_false_when_no_ips(): void
    {
        $user = User::factory()->create();

        $result = $this->service->assemble($user);

        $this->assertFalse($result['allRateLimited']);
    }

    public function test_assemble_ip_count(): void
    {
        $user = User::factory()->create();
        $ip1 = IpAddress::factory()->create();
        $ip2 = IpAddress::factory()->create();
        $this->linkIpToUser($user, $ip1);
        $this->linkIpToUser($user, $ip2);

        $result = $this->service->assemble($user);

        $this->assertSame(2, $result['ipCount']);
    }

    public function test_assemble_includes_audit_logs(): void
    {
        $user = User::factory()->create();

        AuditLog::factory()->create([
            'subject_type' => $user->getMorphClass(),
            'subject_id' => $user->id,
            'action' => 'user.block_toggled',
            'process' => 'admin',
        ]);

        $result = $this->service->assemble($user);

        $this->assertCount(1, $result['auditLogs']);
        $first = $result['auditLogs']->first();
        $this->assertSame('user.block_toggled', $first['action']);
        $this->assertSame('admin', $first['process']);
    }

    public function test_build_network_devices_with_mac_and_ip(): void
    {
        $user = User::factory()->create();
        $ip = IpAddress::factory()->create(['internet_enabled' => true, 'rate_limit_enabled' => false]);
        $this->linkIpToUser($user, $ip);

        $mac = MacAddress::factory()->create(['user_id' => $user->id]);
        $mac->ipAddresses()->attach($ip, ['source' => 'arp', 'last_seen_at' => now()]);

        $ipModels = collect([$ip]);

        $devices = $this->service->buildNetworkDevices($user, $ipModels);

        $this->assertCount(1, $devices);
        $this->assertSame($mac->mac_address, $devices[0]['mac_address']);
        $this->assertSame($ip->address, $devices[0]['ip_address']);
        $this->assertTrue($devices[0]['internet_enabled']);
        $this->assertFalse($devices[0]['rate_limit_enabled']);
    }

    public function test_build_network_devices_ip_without_mac(): void
    {
        $user = User::factory()->create();
        $ip = IpAddress::factory()->create(['internet_enabled' => true, 'rate_limit_enabled' => false]);
        $this->linkIpToUser($user, $ip);

        $ipModels = collect([$ip]);

        $devices = $this->service->buildNetworkDevices($user, $ipModels);

        $this->assertCount(1, $devices);
        $this->assertNull($devices[0]['mac_address']);
        $this->assertSame($ip->address, $devices[0]['ip_address']);
    }

    public function test_build_network_devices_mac_without_relevant_ip(): void
    {
        $user = User::factory()->create();
        $mac = MacAddress::factory()->create(['user_id' => $user->id]);
        $otherIp = IpAddress::factory()->create();
        $mac->ipAddresses()->attach($otherIp, ['source' => 'arp', 'last_seen_at' => now()]);

        $devices = $this->service->buildNetworkDevices($user, collect());

        $this->assertCount(1, $devices);
        $this->assertSame($mac->mac_address, $devices[0]['mac_address']);
        $this->assertNull($devices[0]['ip_address']);
    }

    public function test_build_network_devices_with_switch_port(): void
    {
        $user = User::factory()->create();
        $ip = IpAddress::factory()->create();
        $this->linkIpToUser($user, $ip);

        $switchConfig = SwitchConfig::factory()->create(['name' => 'Core Switch']);
        $switchPort = SwitchPort::factory()->create([
            'switch_config_id' => $switchConfig->id,
            'port_name' => 'Gi0/1',
        ]);

        $mac = MacAddress::factory()->create(['user_id' => $user->id]);
        $mac->ipAddresses()->attach($ip, ['source' => 'arp', 'last_seen_at' => now()]);
        $mac->switchPorts()->attach($switchPort, ['mac_address' => $mac->mac_address, 'last_seen_at' => now()]);

        $devices = $this->service->buildNetworkDevices($user, collect([$ip]));

        $this->assertSame('Core Switch', $devices[0]['switch_name']);
        $this->assertSame($switchConfig->id, $devices[0]['switch_id']);
        $this->assertSame('Gi0/1', $devices[0]['port_name']);
    }

    public function test_assemble_empty_network_devices_for_user_without_ips(): void
    {
        $user = User::factory()->create();

        $result = $this->service->assemble($user);

        $this->assertEmpty($result['networkDevices']);
    }

    public function test_audit_logs_limited_to_twenty(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 25; $i++) {
            AuditLog::factory()->create([
                'subject_type' => $user->getMorphClass(),
                'subject_id' => $user->id,
                'action' => 'user.action.'.$i,
                'process' => 'admin',
            ]);
        }

        $result = $this->service->assemble($user);

        $this->assertCount(20, $result['auditLogs']);
    }

    public function test_build_network_devices_does_not_n_plus_one(): void
    {
        $user = User::factory()->create();
        MacAddress::factory()->count(3)->create(['user_id' => $user->id]);

        DB::enableQueryLog();

        $service = new UserShowDataService;
        $ipModels = collect();
        $service->buildNetworkDevices($user, $ipModels);

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // ≤4 queries: macs, switchPorts eager load, ipAddresses eager load, dhcpLeases eager load
        // (not N queries per MAC — original code issued 11 queries for 3 MACs)
        $this->assertLessThanOrEqual(4, $queryCount,
            "Expected ≤4 queries, got {$queryCount}. N+1 detected.");
    }
}
