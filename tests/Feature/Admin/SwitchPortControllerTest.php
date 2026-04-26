<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Jobs\SwitchPortActionJob;
use App\Jobs\SyncSwitchPortsJob;
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Models\Role;
use App\Models\SwitchConfig;
use App\Models\SwitchPort;
use App\Models\SwitchPortConfig;
use App\Models\SwitchPortMac;
use App\Models\User;
use App\Models\UserIpAddress;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SwitchPortControllerTest extends TestCase
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

    // -------------------------------------------------------------------------
    // Authentication & Authorization
    // -------------------------------------------------------------------------

    public function test_unauthenticated_user_is_redirected_from_switch_port_show(): void
    {
        $switch = SwitchConfig::factory()->create();

        $response = $this->get('/admin/switches/'.$switch->id.'/ports/Gi0%2F1');

        $response->assertRedirect('/captive');
    }

    public function test_unauthenticated_user_is_redirected_from_port_shutdown(): void
    {
        $switch = SwitchConfig::factory()->create();

        $response = $this->post('/admin/switches/'.$switch->id.'/ports/Gi0%2F1/shutdown');

        $response->assertRedirect('/captive');
    }

    public function test_unauthenticated_user_is_redirected_from_port_enable(): void
    {
        $switch = SwitchConfig::factory()->create();

        $response = $this->post('/admin/switches/'.$switch->id.'/ports/Gi0%2F1/enable');

        $response->assertRedirect('/captive');
    }

    public function test_unauthenticated_user_is_redirected_from_port_refresh(): void
    {
        $switch = SwitchConfig::factory()->create();

        $response = $this->post('/admin/switches/'.$switch->id.'/ports/Gi0%2F1/refresh');

        $response->assertRedirect('/captive');
    }

    public function test_non_admin_cannot_view_switch_port(): void
    {
        $user = User::factory()->create();
        $switch = SwitchConfig::factory()->create();

        $this->actingAs($user)->get('/admin/switches/'.$switch->id.'/ports/Gi0%2F1')->assertForbidden();
    }

    public function test_non_admin_cannot_shutdown_port(): void
    {
        $user = User::factory()->create();
        $switch = SwitchConfig::factory()->create();

        $this->actingAs($user)->post('/admin/switches/'.$switch->id.'/ports/Gi0%2F1/shutdown')->assertForbidden();
    }

    public function test_non_admin_cannot_enable_port(): void
    {
        $user = User::factory()->create();
        $switch = SwitchConfig::factory()->create();

        $this->actingAs($user)->post('/admin/switches/'.$switch->id.'/ports/Gi0%2F1/enable')->assertForbidden();
    }

    public function test_non_admin_cannot_refresh_port(): void
    {
        $user = User::factory()->create();
        $switch = SwitchConfig::factory()->create();

        $this->actingAs($user)->post('/admin/switches/'.$switch->id.'/ports/Gi0%2F1/refresh')->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // Show — data-testid: switch-port-show-page
    // -------------------------------------------------------------------------

    public function test_admin_can_view_switch_port_details(): void
    {
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create([
            'name' => 'Core Switch',
        ]);

        SwitchPort::factory()->create([
            'switch_config_id' => $switch->id,
            'port_name' => 'Gi0/1',
            'status' => 'up',
            'speed' => '1000',
        ]);

        $response = $this->actingAs($admin)->get('/admin/switches/'.$switch->id.'/ports/Gi0%2F1');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Switches/Ports/Show')
            ->has('port')
            ->has('macs')
            ->has('switchConfig')
            ->where('switchConfig.name', 'Core Switch')
            ->where('port.interface', 'Gi0/1')
        );
    }

    public function test_port_show_includes_switch_context(): void
    {
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create([
            'name' => 'Edge Switch',
            'hostname' => 'edge-sw.local',
        ]);

        SwitchPort::factory()->create([
            'switch_config_id' => $switch->id,
            'port_name' => 'eth0',
            'status' => 'up',
            'speed' => '100',
        ]);

        $response = $this->actingAs($admin)->get('/admin/switches/'.$switch->id.'/ports/eth0');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Switches/Ports/Show')
            ->where('switchConfig.name', 'Edge Switch')
            ->where('switchConfig.hostname', 'edge-sw.local')
        );
    }

    public function test_port_show_includes_interface_output_property_alongside_running_config(): void
    {
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create();

        $port = SwitchPort::factory()->create([
            'switch_config_id' => $switch->id,
            'port_name' => 'Gi0/1',
            'status' => 'up',
            'speed' => '1000',
        ]);

        SwitchPortConfig::factory()->create([
            'switch_port_id' => $port->id,
            'config_text' => "interface Gi0/1\n description Test",
        ]);

        $response = $this->actingAs($admin)->get('/admin/switches/'.$switch->id.'/ports/Gi0%2F1');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('port.config_text', "interface Gi0/1\n description Test")
            ->has('port.interface_output')
        );
    }

    public function test_port_show_returns_404_for_nonexistent_switch(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/admin/switches/99999/ports/Gi0%2F1');

        $response->assertNotFound();
    }

    public function test_port_show_returns_404_when_port_not_found(): void
    {
        $user = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('admin.switches.ports.show', [$switch, 'NonExistentPort']));

        $response->assertNotFound();
    }

    public function test_port_show_includes_resolved_ips_for_macs(): void
    {
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create();

        $port = SwitchPort::factory()->create([
            'switch_config_id' => $switch->id,
            'port_name' => 'Gi0/1',
            'status' => 'up',
            'speed' => '1000',
        ]);

        $macRecord = MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:FF']);
        $ipRecord = IpAddress::factory()->create(['address' => '10.0.0.10']);
        $macRecord->ipAddresses()->attach($ipRecord->id, ['source' => 'dhcp', 'last_seen_at' => now()]);

        SwitchPortMac::factory()->create([
            'switch_port_id' => $port->id,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'mac_address_id' => $macRecord->id,
            'vlan' => 100,
        ]);

        $response = $this->actingAs($admin)->get('/admin/switches/'.$switch->id.'/ports/Gi0%2F1');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Switches/Ports/Show')
            ->has('macs', 1)
            ->where('macs.0.mac_address', 'AA:BB:CC:DD:EE:FF')
            ->where('macs.0.mac_id', $macRecord->id)
            ->where('macs.0.resolved_ips.0.ip', '10.0.0.10')
            ->where('macs.0.resolved_ips.0.user', null)
        );
    }

    public function test_port_show_returns_empty_resolved_ips_when_no_mac_record(): void
    {
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create();

        $port = SwitchPort::factory()->create([
            'switch_config_id' => $switch->id,
            'port_name' => 'Gi0/1',
            'status' => 'up',
            'speed' => '1000',
        ]);

        SwitchPortMac::factory()->create([
            'switch_port_id' => $port->id,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'mac_address_id' => null,
            'vlan' => 100,
        ]);

        $response = $this->actingAs($admin)->get('/admin/switches/'.$switch->id.'/ports/Gi0%2F1');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Switches/Ports/Show')
            ->has('macs', 1)
            ->where('macs.0.mac_id', null)
            ->where('macs.0.resolved_ips', [])
        );
    }

    // -------------------------------------------------------------------------
    // Prev / Next Port Navigation
    // -------------------------------------------------------------------------

    public function test_port_show_includes_prev_and_next_ports(): void
    {
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create();

        SwitchPort::factory()->create(['switch_config_id' => $switch->id, 'port_name' => 'Gi0/1']);
        SwitchPort::factory()->create(['switch_config_id' => $switch->id, 'port_name' => 'Gi0/2']);
        SwitchPort::factory()->create(['switch_config_id' => $switch->id, 'port_name' => 'Gi0/3']);

        $response = $this->actingAs($admin)->get('/admin/switches/'.$switch->id.'/ports/Gi0%2F2');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Switches/Ports/Show')
            ->where('prevPort', 'Gi0/1')
            ->where('nextPort', 'Gi0/3')
        );
    }

    public function test_port_show_has_no_prev_for_first_port(): void
    {
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create();

        SwitchPort::factory()->create(['switch_config_id' => $switch->id, 'port_name' => 'Gi0/1']);
        SwitchPort::factory()->create(['switch_config_id' => $switch->id, 'port_name' => 'Gi0/2']);

        $response = $this->actingAs($admin)->get('/admin/switches/'.$switch->id.'/ports/Gi0%2F1');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Switches/Ports/Show')
            ->where('prevPort', null)
            ->where('nextPort', 'Gi0/2')
        );
    }

    public function test_port_show_has_no_next_for_last_port(): void
    {
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create();

        SwitchPort::factory()->create(['switch_config_id' => $switch->id, 'port_name' => 'Gi0/1']);
        SwitchPort::factory()->create(['switch_config_id' => $switch->id, 'port_name' => 'Gi0/2']);

        $response = $this->actingAs($admin)->get('/admin/switches/'.$switch->id.'/ports/Gi0%2F2');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Switches/Ports/Show')
            ->where('prevPort', 'Gi0/1')
            ->where('nextPort', null)
        );
    }

    public function test_port_show_has_no_prev_or_next_for_single_port(): void
    {
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create();

        SwitchPort::factory()->create(['switch_config_id' => $switch->id, 'port_name' => 'Gi0/1']);

        $response = $this->actingAs($admin)->get('/admin/switches/'.$switch->id.'/ports/Gi0%2F1');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Switches/Ports/Show')
            ->where('prevPort', null)
            ->where('nextPort', null)
        );
    }

    // -------------------------------------------------------------------------
    // Refresh — dispatches SyncSwitchPortsJob
    // -------------------------------------------------------------------------

    public function test_admin_can_refresh_port(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create();

        $response = $this->actingAs($admin)->post('/admin/switches/'.$switch->id.'/ports/Gi0%2F1/refresh');

        $response->assertRedirect();
        $response->assertSessionHas('success');
        Queue::assertPushed(SyncSwitchPortsJob::class, fn (SyncSwitchPortsJob $job): bool => $job->switchConfig->id === $switch->id);
    }

    public function test_refresh_returns_404_for_nonexistent_switch(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->post('/admin/switches/99999/ports/Gi0%2F1/refresh');

        $response->assertNotFound();
    }

    // -------------------------------------------------------------------------
    // Shutdown — dispatches SwitchPortActionJob
    // -------------------------------------------------------------------------

    public function test_admin_can_shutdown_port(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create();

        $response = $this->actingAs($admin)->post('/admin/switches/'.$switch->id.'/ports/Gi0%2F1/shutdown');

        $response->assertRedirect();
        $response->assertSessionHas('success');
        Queue::assertPushed(SwitchPortActionJob::class, fn (SwitchPortActionJob $job): bool => $job->switchConfig->id === $switch->id
            && $job->portId === 'Gi0/1'
            && $job->action === 'shutdown');
    }

    public function test_shutdown_returns_redirect(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create();

        $response = $this->actingAs($admin)->post('/admin/switches/'.$switch->id.'/ports/eth0/shutdown');

        $response->assertRedirect();
    }

    public function test_shutdown_returns_404_for_nonexistent_switch(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->post('/admin/switches/99999/ports/Gi0%2F1/shutdown');

        $response->assertNotFound();
    }

    // -------------------------------------------------------------------------
    // Enable — dispatches SwitchPortActionJob
    // -------------------------------------------------------------------------

    public function test_admin_can_enable_port(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create();

        $response = $this->actingAs($admin)->post('/admin/switches/'.$switch->id.'/ports/Gi0%2F1/enable');

        $response->assertRedirect();
        $response->assertSessionHas('success');
        Queue::assertPushed(SwitchPortActionJob::class, fn (SwitchPortActionJob $job): bool => $job->switchConfig->id === $switch->id
            && $job->portId === 'Gi0/1'
            && $job->action === 'enable');
    }

    public function test_enable_returns_404_for_nonexistent_switch(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->post('/admin/switches/99999/ports/Gi0%2F1/enable');

        $response->assertNotFound();
    }

    // -------------------------------------------------------------------------
    // Switch scoping — ensure port actions operate on the correct switch
    // -------------------------------------------------------------------------

    public function test_port_actions_are_scoped_to_switch(): void
    {
        $admin = $this->createAdminUser();
        $switch1 = SwitchConfig::factory()->create(['name' => 'Switch A']);
        SwitchConfig::factory()->create(['name' => 'Switch B']);

        SwitchPort::factory()->create([
            'switch_config_id' => $switch1->id,
            'port_name' => 'Gi0/1',
            'status' => 'up',
            'speed' => '1000',
        ]);

        $response = $this->actingAs($admin)->get('/admin/switches/'.$switch1->id.'/ports/Gi0%2F1');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Switches/Ports/Show')
            ->where('switchConfig.name', 'Switch A')
        );
    }

    public function test_port_shutdown_dispatches_job_for_correct_switch(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create(['name' => 'Target Switch']);

        $response = $this->actingAs($admin)->post('/admin/switches/'.$switch->id.'/ports/Gi0%2F2/shutdown');

        $response->assertRedirect();
        Queue::assertPushed(SwitchPortActionJob::class, fn (SwitchPortActionJob $job): bool => $job->switchConfig->id === $switch->id
            && $job->portId === 'Gi0/2'
            && $job->action === 'shutdown');
    }

    // -------------------------------------------------------------------------
    // IP resolution
    // -------------------------------------------------------------------------

    public function test_port_show_handles_mac_with_no_linked_record_gracefully(): void
    {
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create();

        $port = SwitchPort::factory()->create([
            'switch_config_id' => $switch->id,
            'port_name' => 'Gi0/1',
            'status' => 'up',
            'speed' => '1000',
        ]);

        SwitchPortMac::factory()->create([
            'switch_port_id' => $port->id,
            'mac_address' => 'AA:BB:CC:DD:EE:01',
            'mac_address_id' => null,
            'vlan' => 100,
        ]);

        $response = $this->actingAs($admin)->get('/admin/switches/'.$switch->id.'/ports/Gi0%2F1');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Switches/Ports/Show')
            ->has('macs', 1)
            ->where('macs.0.mac_id', null)
            ->where('macs.0.resolved_ips', [])
        );
    }

    // -------------------------------------------------------------------------
    // toIso8601String
    // -------------------------------------------------------------------------

    public function test_port_show_has_null_last_synced_at_when_not_set(): void
    {
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create();

        SwitchPort::factory()->create([
            'switch_config_id' => $switch->id,
            'port_name' => 'Gi0/1',
            'status' => 'up',
            'speed' => '1000',
            'last_synced_at' => null,
        ]);

        $response = $this->actingAs($admin)->get('/admin/switches/'.$switch->id.'/ports/Gi0%2F1');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Switches/Ports/Show')
            ->where('port.last_synced_at', null)
        );
    }

    // -------------------------------------------------------------------------
    // MAC → IP → User resolution
    // -------------------------------------------------------------------------

    public function test_port_show_includes_user_data_when_ip_user_relationship_exists(): void
    {
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create();

        $port = SwitchPort::factory()->create([
            'switch_config_id' => $switch->id,
            'port_name' => 'Gi0/1',
            'status' => 'up',
            'speed' => '1000',
        ]);

        $connectedUser = User::factory()->create(['nickname' => 'NeonGamer42']);
        $ipRecord = IpAddress::factory()->create(['address' => '10.0.0.42']);
        $macRecord = MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:FF']);
        $macRecord->ipAddresses()->attach($ipRecord->id, ['source' => 'dhcp', 'last_seen_at' => now()]);

        $userIp = new UserIpAddress;
        $userIp->user()->associate($connectedUser);
        $userIp->ip()->associate($ipRecord);
        $userIp->last_seen_at = now();
        $userIp->save();

        SwitchPortMac::factory()->create([
            'switch_port_id' => $port->id,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'mac_address_id' => $macRecord->id,
            'vlan' => 100,
        ]);

        $response = $this->actingAs($admin)->get('/admin/switches/'.$switch->id.'/ports/Gi0%2F1');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Switches/Ports/Show')
            ->has('macs', 1)
            ->where('macs.0.mac_id', $macRecord->id)
            ->where('macs.0.resolved_ips.0.ip', '10.0.0.42')
            ->where('macs.0.resolved_ips.0.user.id', $connectedUser->id)
            ->where('macs.0.resolved_ips.0.user.nickname', 'NeonGamer42')
        );
    }

    public function test_port_show_returns_null_user_when_no_user_for_ip(): void
    {
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create();

        $port = SwitchPort::factory()->create([
            'switch_config_id' => $switch->id,
            'port_name' => 'Gi0/1',
            'status' => 'up',
            'speed' => '1000',
        ]);

        $ipRecord = IpAddress::factory()->create(['address' => '10.0.0.55']);
        $macRecord = MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:FF']);
        $macRecord->ipAddresses()->attach($ipRecord->id, ['source' => 'dhcp', 'last_seen_at' => now()]);

        SwitchPortMac::factory()->create([
            'switch_port_id' => $port->id,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'mac_address_id' => $macRecord->id,
            'vlan' => 200,
        ]);

        $response = $this->actingAs($admin)->get('/admin/switches/'.$switch->id.'/ports/Gi0%2F1');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Switches/Ports/Show')
            ->has('macs', 1)
            ->where('macs.0.mac_id', $macRecord->id)
            ->where('macs.0.resolved_ips.0.ip', '10.0.0.55')
            ->where('macs.0.resolved_ips.0.user', null)
        );
    }

    public function test_port_show_returns_empty_resolved_ips_when_no_ip_linked_to_mac(): void
    {
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create();

        $port = SwitchPort::factory()->create([
            'switch_config_id' => $switch->id,
            'port_name' => 'Gi0/1',
            'status' => 'up',
            'speed' => '1000',
        ]);

        $macRecord = MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:FF']);

        SwitchPortMac::factory()->create([
            'switch_port_id' => $port->id,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'mac_address_id' => $macRecord->id,
            'vlan' => 100,
        ]);

        $response = $this->actingAs($admin)->get('/admin/switches/'.$switch->id.'/ports/Gi0%2F1');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Switches/Ports/Show')
            ->has('macs', 1)
            ->where('macs.0.mac_id', $macRecord->id)
            ->where('macs.0.resolved_ips', [])
        );
    }

    public function test_port_show_macs_work_when_no_ip_linked(): void
    {
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create();

        $port = SwitchPort::factory()->create([
            'switch_config_id' => $switch->id,
            'port_name' => 'Gi0/1',
            'status' => 'up',
            'speed' => '1000',
        ]);

        $macRecord = MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:FF:FE:01']);

        SwitchPortMac::factory()->create([
            'switch_port_id' => $port->id,
            'mac_address' => 'AA:BB:CC:FF:FE:01',
            'mac_address_id' => $macRecord->id,
            'vlan' => 1,
        ]);

        $response = $this->actingAs($admin)->get('/admin/switches/'.$switch->id.'/ports/Gi0%2F1');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Switches/Ports/Show')
            ->has('macs', 1)
            ->where('macs.0.mac_address', 'AA:BB:CC:FF:FE:01')
            ->where('macs.0.mac_id', $macRecord->id)
            ->where('macs.0.resolved_ips', [])
        );
    }

    // -------------------------------------------------------------------------
    // Route-level command injection prevention (#9)
    // -------------------------------------------------------------------------

    public function test_route_rejects_port_id_with_semicolon_injection(): void
    {
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create();

        $response = $this->actingAs($admin)->get('/admin/switches/'.$switch->id.'/ports/Gi0%2F1%3B+show+run');

        $response->assertNotFound();
    }

    public function test_route_rejects_port_id_with_pipe_injection(): void
    {
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create();

        $response = $this->actingAs($admin)->get('/admin/switches/'.$switch->id.'/ports/Gi0%2F1+%7C+include+password');

        $response->assertNotFound();
    }

    public function test_route_rejects_port_id_with_newline_injection(): void
    {
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create();

        $response = $this->actingAs($admin)->get('/admin/switches/'.$switch->id.'/ports/Gi0%2F1%0Ashow+run');

        $response->assertNotFound();
    }

    public function test_route_rejects_port_id_with_backtick_injection(): void
    {
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create();

        $response = $this->actingAs($admin)->get('/admin/switches/'.$switch->id.'/ports/Gi0%2F1%60show+run%60');

        $response->assertNotFound();
    }

    public function test_shutdown_route_rejects_command_injection(): void
    {
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create();

        $response = $this->actingAs($admin)->post('/admin/switches/'.$switch->id.'/ports/Gi0%2F1%3B+show+run/shutdown');

        $response->assertNotFound();
    }

    public function test_enable_route_rejects_command_injection(): void
    {
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create();

        $response = $this->actingAs($admin)->post('/admin/switches/'.$switch->id.'/ports/Gi0%2F1%3B+show+run/enable');

        $response->assertNotFound();
    }

    public function test_refresh_route_rejects_command_injection(): void
    {
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create();

        $response = $this->actingAs($admin)->post('/admin/switches/'.$switch->id.'/ports/Gi0%2F1%3B+show+run/refresh');

        $response->assertNotFound();
    }

    public function test_route_accepts_valid_cisco_port_formats(): void
    {
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create();

        // Gi0/1 - should match route (404 is from missing port record, not route)
        SwitchPort::factory()->create([
            'switch_config_id' => $switch->id,
            'port_name' => 'Gi0/1',
        ]);

        $response = $this->actingAs($admin)->get('/admin/switches/'.$switch->id.'/ports/Gi0%2F1');
        $response->assertOk();
    }

    public function test_route_accepts_port_channel_format(): void
    {
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create();

        SwitchPort::factory()->create([
            'switch_config_id' => $switch->id,
            'port_name' => 'Po1',
        ]);

        $response = $this->actingAs($admin)->get('/admin/switches/'.$switch->id.'/ports/Po1');
        $response->assertOk();
    }

    public function test_route_accepts_vlan_format(): void
    {
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create();

        SwitchPort::factory()->create([
            'switch_config_id' => $switch->id,
            'port_name' => 'Vl100',
        ]);

        $response = $this->actingAs($admin)->get('/admin/switches/'.$switch->id.'/ports/Vl100');
        $response->assertOk();
    }

    public function test_route_accepts_ten_gigabit_format(): void
    {
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create();

        SwitchPort::factory()->create([
            'switch_config_id' => $switch->id,
            'port_name' => 'Te1/1/1',
        ]);

        $response = $this->actingAs($admin)->get('/admin/switches/'.$switch->id.'/ports/Te1%2F1%2F1');
        $response->assertOk();
    }

    // -------------------------------------------------------------------------
    // Route names
    // -------------------------------------------------------------------------

    public function test_route_names_for_switch_ports(): void
    {
        $switch = SwitchConfig::factory()->create();

        $this->assertStringContainsString(
            '/admin/switches/'.$switch->id.'/ports/',
            route('admin.switches.ports.show', [$switch, 'Gi0/1'])
        );
        $this->assertStringContainsString(
            '/ports/Gi0%2F1/shutdown',
            route('admin.switches.ports.shutdown', [$switch, 'Gi0/1'])
        );
        $this->assertStringContainsString(
            '/ports/Gi0%2F1/enable',
            route('admin.switches.ports.enable', [$switch, 'Gi0/1'])
        );
        $this->assertStringContainsString(
            '/ports/Gi0%2F1/refresh',
            route('admin.switches.ports.refresh', [$switch, 'Gi0/1'])
        );
    }
}
