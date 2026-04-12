<?php

namespace Tests\Feature\Console;

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

        config([
            'aperture.opnsense.endpoint' => 'http://127.0.0.1:19199',
            'aperture.opnsense.key' => 'key',
            'aperture.opnsense.secret' => 'secret',
            'aperture.opnsense.verify' => false,
            'aperture.opnsense.zoneid' => 1,
            'aperture.opnsense.ratelimitUpUuid' => 'up-uuid',
            'aperture.opnsense.ratelimitDownUuid' => 'down-uuid',
        ]);
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
