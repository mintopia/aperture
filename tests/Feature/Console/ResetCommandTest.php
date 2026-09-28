<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Jobs\ResetAperture;
use App\Models\IpAddress;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ResetCommandTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Sync jobs fired by model observers would call the firewall backends; keep them off the wire.
        Queue::fake()->except(ResetAperture::class);
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
        $ip->rate_limit_enabled = false;
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
        $ip->rate_limit_enabled = true;
        $ip->save();

        $this->artisan('aperture:reset')
            ->expectsConfirmation('Are you sure you want to reset Aperture?', 'yes')
            ->expectsOutput('Finished')
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
