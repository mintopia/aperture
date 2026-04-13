<?php

namespace Tests\Feature\Console;

use App\Models\IntegrationConfig;
use App\Models\IpAddress;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResetCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        IntegrationConfig::setValue('opnsense', 'endpoint', 'http://127.0.0.1:19199');
        IntegrationConfig::setValue('opnsense', 'key', 'key', true);
        IntegrationConfig::setValue('opnsense', 'secret', 'secret', true);
        IntegrationConfig::setValue('opnsense', 'verify_ssl', '0');
        IntegrationConfig::setValue('opnsense', 'zone_id', '1');
        IntegrationConfig::setValue('opnsense', 'ratelimit_up_uuid', 'up-uuid');
        IntegrationConfig::setValue('opnsense', 'ratelimit_down_uuid', 'down-uuid');
    }

    public function test_command_exits_when_not_confirmed(): void
    {
        $this->artisan('aperture:reset')
            ->expectsConfirmation('Are you sure you want to reset Aperture?', 'no')
            ->assertSuccessful();
    }

    public function test_command_deletes_non_admin_users_and_ips(): void
    {
        $adminRole = new Role;
        $adminRole->name = 'Admin';
        $adminRole->code = 'admin';
        $adminRole->save();

        $admin = User::factory()->create();
        $admin->roles()->attach($adminRole);

        $regularUser = User::factory()->create();

        $ip = new IpAddress;
        $ip->address = '10.0.0.1';
        $ip->last_seen_at = now();
        $ip->limited = false;
        $ip->save();

        $this->artisan('aperture:reset')
            ->expectsConfirmation('Are you sure you want to reset Aperture?', 'yes')
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
        $this->assertDatabaseMissing('users', ['id' => $regularUser->id]);
        $this->assertDatabaseMissing('ip_addresses', ['address' => '10.0.0.1']);
    }

    public function test_command_unlimits_limited_ips(): void
    {
        $adminRole = new Role;
        $adminRole->name = 'Admin';
        $adminRole->code = 'admin';
        $adminRole->save();

        $admin = User::factory()->create();
        $admin->roles()->attach($adminRole);

        $ip = new IpAddress;
        $ip->address = '10.0.0.2';
        $ip->last_seen_at = now();
        $ip->limited = true;
        $ip->save();

        $this->artisan('aperture:reset')
            ->expectsConfirmation('Are you sure you want to reset Aperture?', 'yes')
            ->assertSuccessful();

        $this->assertDatabaseMissing('ip_addresses', ['address' => '10.0.0.2']);
    }

    public function test_command_deletes_non_admin_users_when_no_ips(): void
    {
        $adminRole = new Role;
        $adminRole->name = 'Admin';
        $adminRole->code = 'admin';
        $adminRole->save();

        $admin = User::factory()->create();
        $admin->roles()->attach($adminRole);

        $regularUser = User::factory()->create();

        $this->artisan('aperture:reset')
            ->expectsConfirmation('Are you sure you want to reset Aperture?', 'yes')
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
        $this->assertDatabaseMissing('users', ['id' => $regularUser->id]);
    }
}
