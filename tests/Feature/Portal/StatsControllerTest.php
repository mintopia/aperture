<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Models\User;
use App\Services\Interfaces\TrafficMonitorInterface;
use App\Services\ValueObjects\UserBandwidth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

class StatsControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_bandwidth_from_traffic_monitor(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->mock(TrafficMonitorInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getUserBandwidth')
                ->once()
                ->andReturn(new UserBandwidth(
                    received: 3072000,
                    sent: 1536000,
                    timestamps: ['1700000000', '1700000300'],
                    download: [1024000, 2048000],
                    upload: [512000, 1024000],
                ));
        });

        $response = $this->actingAs($user)->getJson('/portal/stats/bandwidth');

        $response->assertOk()
            ->assertJsonStructure(['timestamps', 'download', 'upload', 'totalReceived', 'totalSent'])
            ->assertJson([
                'totalReceived' => 3072000,
                'totalSent' => 1536000,
                'timestamps' => ['1700000000', '1700000300'],
                'download' => [1024000, 2048000],
                'upload' => [512000, 1024000],
            ]);
    }

    public function test_passes_client_ip_to_traffic_monitor(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->mock(TrafficMonitorInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getUserBandwidth')
                ->with('44.30.69.131', '24h')
                ->once()
                ->andReturn(new UserBandwidth(
                    received: 0,
                    sent: 0,
                    timestamps: [],
                    download: [],
                    upload: [],
                ));
        });

        $response = $this->actingAs($user)
            ->withServerVariables(['REMOTE_ADDR' => '44.30.69.131'])
            ->getJson('/portal/stats/bandwidth');

        $response->assertOk();
    }

    public function test_accepts_range_query_parameter(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->mock(TrafficMonitorInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getUserBandwidth')
                ->withArgs(fn (string $ip, string $range): bool => $range === '1h')
                ->once()
                ->andReturn(new UserBandwidth(
                    received: 0,
                    sent: 0,
                    timestamps: [],
                    download: [],
                    upload: [],
                ));
        });

        $response = $this->actingAs($user)->getJson('/portal/stats/bandwidth?range=1h');

        $response->assertOk();
    }

    public function test_returns_empty_data_when_no_prometheus_data(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->mock(TrafficMonitorInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getUserBandwidth')
                ->once()
                ->andReturn(new UserBandwidth(
                    received: 0,
                    sent: 0,
                    timestamps: [],
                    download: [],
                    upload: [],
                ));
        });

        $response = $this->actingAs($user)->getJson('/portal/stats/bandwidth');

        $response->assertOk()
            ->assertJson([
                'totalReceived' => 0,
                'totalSent' => 0,
                'timestamps' => [],
                'download' => [],
                'upload' => [],
            ]);
    }

    public function test_unauthenticated_user_rejected(): void
    {
        $response = $this->getJson('/portal/stats/bandwidth');

        $response->assertUnauthorized();
    }
}
