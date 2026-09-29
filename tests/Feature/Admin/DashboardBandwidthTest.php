<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Role;
use App\Models\User;
use App\Services\Interfaces\IpBandwidthInterface;
use App\Services\ValueObjects\IpBandwidthResult;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DashboardBandwidthTest extends TestCase
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

    #[DataProvider('bandwidthRangeProvider')]
    public function test_bandwidth_accepts_range_parameter(string $queryString, string $expectedRange): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $this->mock(IpBandwidthInterface::class, function (MockInterface $mock) use ($expectedRange): void {
            $mock->shouldReceive('getTotalBandwidth')
                ->once()
                ->with($expectedRange)
                ->andReturn(new IpBandwidthResult(
                    received: 0,
                    sent: 0,
                    timestamps: [],
                    download: [],
                    upload: [],
                ));
        });

        $response = $this->actingAs($admin)->getJson('/admin/bandwidth'.$queryString);

        $response->assertOk();
    }

    public static function bandwidthRangeProvider(): array
    {
        return [
            '1 hour' => ['?range=1h', '1h'],
            '4 days' => ['?range=4d', '4d'],
            '7 days' => ['?range=7d', '7d'],
            'defaults to 24h when omitted' => ['', '24h'],
        ];
    }

    #[DataProvider('invalidBandwidthRangeProvider')]
    public function test_bandwidth_rejects_invalid_range(string $range): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->getJson('/admin/bandwidth?range='.$range);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['range']);
    }

    public static function invalidBandwidthRangeProvider(): array
    {
        return [
            '72h' => ['72h'],
            '99d' => ['99d'],
            'arbitrary string' => ['invalid'],
        ];
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
