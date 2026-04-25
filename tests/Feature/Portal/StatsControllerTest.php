<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Models\IpAddress;
use App\Models\IpAddressMacAddress;
use App\Models\MacAddress;
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
                    download: [8192000.0, 16384000.0],
                    upload: [4096000.0, 8192000.0],
                ));
        });

        $response = $this->actingAs($user)->getJson('/portal/stats/bandwidth');

        $response->assertOk()
            ->assertJsonStructure(['timestamps', 'download', 'upload', 'totalReceived', 'totalSent'])
            ->assertJson([
                'totalReceived' => 3072000,
                'totalSent' => 1536000,
                'timestamps' => ['1700000000', '1700000300'],
                'download' => [8192000.0, 16384000.0],
                'upload' => [4096000.0, 8192000.0],
            ]);
    }

    public function test_passes_client_ip_when_no_mac_found(): void
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

    public function test_resolves_all_ips_for_mac_address(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $mac = MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:FF']);
        $ip1 = IpAddress::factory()->create(['address' => '10.0.0.10']);
        $ip2 = IpAddress::factory()->create(['address' => '10.0.0.11']);

        IpAddressMacAddress::factory()->create([
            'ip_address_id' => $ip1->id,
            'mac_address_id' => $mac->id,
            'last_seen_at' => now(),
        ]);
        IpAddressMacAddress::factory()->create([
            'ip_address_id' => $ip2->id,
            'mac_address_id' => $mac->id,
            'last_seen_at' => now()->subMinute(),
        ]);

        $this->mock(TrafficMonitorInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getUserBandwidth')
                ->withArgs(function (string|array $ips, string $range): bool {
                    if (! is_array($ips)) {
                        return false;
                    }
                    sort($ips);

                    return $ips === ['10.0.0.10', '10.0.0.11'] && $range === '24h';
                })
                ->once()
                ->andReturn(new UserBandwidth(
                    received: 5000000,
                    sent: 2000000,
                    timestamps: ['1700000000'],
                    download: [40000000.0],
                    upload: [16000000.0],
                ));
        });

        $response = $this->actingAs($user)
            ->withServerVariables(['REMOTE_ADDR' => '10.0.0.10'])
            ->getJson('/portal/stats/bandwidth');

        $response->assertOk()
            ->assertJson(['totalReceived' => 5000000]);
    }

    public function test_falls_back_to_single_ip_when_only_one_ip_for_mac(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $mac = MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:FF']);
        $ip = IpAddress::factory()->create(['address' => '10.0.0.10']);

        IpAddressMacAddress::factory()->create([
            'ip_address_id' => $ip->id,
            'mac_address_id' => $mac->id,
            'last_seen_at' => now(),
        ]);

        $this->mock(TrafficMonitorInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getUserBandwidth')
                ->with('10.0.0.10', '24h')
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
            ->withServerVariables(['REMOTE_ADDR' => '10.0.0.10'])
            ->getJson('/portal/stats/bandwidth');

        $response->assertOk();
    }

    public function test_accepts_range_query_parameter(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->mock(TrafficMonitorInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getUserBandwidth')
                ->withArgs(fn (string|array $ip, string $range): bool => $range === '1h')
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
