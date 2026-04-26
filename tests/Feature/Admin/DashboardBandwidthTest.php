<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Role;
use App\Models\User;
use App\Services\Interfaces\IpBandwidthInterface;
use App\Services\ValueObjects\IpBandwidthResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

class DashboardBandwidthTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_bandwidth_returns_json_with_expected_keys(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $this->mock(IpBandwidthInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getTotalBandwidth')
                ->once()
                ->with('24h')
                ->andReturn(new IpBandwidthResult(
                    received: 1024000,
                    sent: 512000,
                    timestamps: ['1700000000', '1700000300'],
                    download: [8192.0, 9000.0],
                    upload: [4096.0, 4500.0],
                ));
        });

        $response = $this->actingAs($admin)->getJson('/admin/bandwidth');

        $response->assertOk()
            ->assertJsonStructure(['timestamps', 'download', 'upload', 'totalReceived', 'totalSent'])
            ->assertJson([
                'timestamps' => ['1700000000', '1700000300'],
                'download' => [8192.0, 9000.0],
                'upload' => [4096.0, 4500.0],
                'totalReceived' => 1024000,
                'totalSent' => 512000,
            ]);
    }

    public function test_bandwidth_accepts_range_parameter(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $this->mock(IpBandwidthInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getTotalBandwidth')
                ->once()
                ->with('1h')
                ->andReturn(new IpBandwidthResult(
                    received: 0,
                    sent: 0,
                    timestamps: [],
                    download: [],
                    upload: [],
                ));
        });

        $response = $this->actingAs($admin)->getJson('/admin/bandwidth?range=1h');

        $response->assertOk();
    }

    public function test_bandwidth_accepts_4d_range(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $this->mock(IpBandwidthInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getTotalBandwidth')
                ->once()
                ->with('4d')
                ->andReturn(new IpBandwidthResult(
                    received: 0,
                    sent: 0,
                    timestamps: [],
                    download: [],
                    upload: [],
                ));
        });

        $response = $this->actingAs($admin)->getJson('/admin/bandwidth?range=4d');

        $response->assertOk();
    }

    public function test_bandwidth_rejects_invalid_range(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->getJson('/admin/bandwidth?range=99d');

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['range']);
    }

    public function test_bandwidth_rejects_arbitrary_string_range(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->getJson('/admin/bandwidth?range=invalid');

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['range']);
    }

    public function test_bandwidth_defaults_to_24h_when_range_is_null(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $this->mock(IpBandwidthInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getTotalBandwidth')
                ->once()
                ->with('24h')
                ->andReturn(new IpBandwidthResult(
                    received: 0,
                    sent: 0,
                    timestamps: [],
                    download: [],
                    upload: [],
                ));
        });

        $response = $this->actingAs($admin)->getJson('/admin/bandwidth');

        $response->assertOk();
    }

    public function test_bandwidth_requires_admin_auth(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/admin/bandwidth');

        $response->assertForbidden();
    }

    public function test_bandwidth_requires_authentication(): void
    {
        $response = $this->getJson('/admin/bandwidth');

        $response->assertUnauthorized();
    }
}
