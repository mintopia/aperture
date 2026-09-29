<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\ResetAperture;
use App\Models\IpAddress;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class ResetApertureJobTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_has_correct_retry_configuration(): void
    {
        $job = new ResetAperture;

        $this->assertSame(3, $job->tries);
        $this->assertSame(120, $job->timeout);
        $this->assertSame([10, 30, 60], $job->backoff());
    }

    public function test_failed_logs_error(): void
    {
        $job = new ResetAperture;

        Log::shouldReceive('error')
            ->once()
            ->with('ResetAperture failed', [
                'error' => 'Database error',
            ]);

        $job->failed(new RuntimeException('Database error'));
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
