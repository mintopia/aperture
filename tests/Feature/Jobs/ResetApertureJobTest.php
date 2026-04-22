<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\ResetAperture;
use App\Models\IpAddress;
use App\Models\Role;
use App\Models\User;
use App\Services\Firewalls\OpnSense;
use App\Services\Interfaces\FirewallBackendInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ResetApertureJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $mock = Mockery::mock(OpnSense::class);
        $mock->shouldReceive('unlimitIp')->andReturnSelf();
        $mock->shouldReceive('removeIp')->andReturnSelf();
        $mock->shouldReceive('updateIp')->andReturnSelf();
        $this->app->instance(FirewallBackendInterface::class, $mock);
    }

    public function test_deletes_all_ips(): void
    {
        $ip = new IpAddress;
        $ip->address = '10.0.0.1';
        $ip->last_seen_at = now();
        $ip->internet_enabled = true;
        $ip->save();

        $job = new ResetAperture;
        $job->handle();

        $this->assertDatabaseMissing('ip_addresses', ['address' => '10.0.0.1']);
    }

    public function test_unlimits_limited_ips(): void
    {
        $ip = new IpAddress;
        $ip->address = '10.0.0.2';
        $ip->last_seen_at = now();
        $ip->rate_limit_enabled = true;
        $ip->save();

        $job = new ResetAperture;
        $job->handle();

        $this->assertDatabaseMissing('ip_addresses', ['address' => '10.0.0.2']);
    }

    public function test_deletes_non_admin_users(): void
    {
        $adminRole = new Role;
        $adminRole->name = 'Admin';
        $adminRole->code = 'admin';
        $adminRole->save();

        $admin = User::factory()->create();
        $admin->roles()->attach($adminRole);

        $regularUser = User::factory()->create();

        $job = new ResetAperture;
        $job->handle();

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
        $this->assertDatabaseMissing('users', ['id' => $regularUser->id]);
    }

    public function test_preserves_admin_users(): void
    {
        $adminRole = new Role;
        $adminRole->name = 'Admin';
        $adminRole->code = 'admin';
        $adminRole->save();

        $admin = User::factory()->create();
        $admin->roles()->attach($adminRole);

        $job = new ResetAperture;
        $job->handle();

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }
}
