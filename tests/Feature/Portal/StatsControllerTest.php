<?php

namespace Tests\Feature\Portal;

use App\Models\IpAddress;
use App\Models\User;
use App\Models\UserIpAddress;
use App\Services\NtopNgService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StatsControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function createUserWithIp(User $user, string $ip, int $received = 0, int $sent = 0): IpAddress
    {
        $ipAddress = new IpAddress;
        $ipAddress->address = $ip;
        $ipAddress->received = $received;
        $ipAddress->sent = $sent;
        $ipAddress->last_seen_at = Carbon::now();
        $ipAddress->save();

        $userIp = new UserIpAddress;
        $userIp->user()->associate($user);
        $userIp->ip()->associate($ipAddress);
        $userIp->last_seen_at = Carbon::now();
        $userIp->save();

        return $ipAddress;
    }

    public function test_returns_bandwidth_data_for_users_ips(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $this->createUserWithIp($user, '192.168.1.10', 1024000, 512000);
        $this->createUserWithIp($user, '192.168.1.11', 2048000, 1024000);

        $this->mock(NtopNgService::class);

        $response = $this->actingAs($user)->getJson('/portal/stats/bandwidth');

        $response->assertOk()
            ->assertJsonStructure(['stats', 'totalReceived', 'totalSent'])
            ->assertJson([
                'totalReceived' => 3072000,
                'totalSent' => 1536000,
            ]);
    }

    public function test_unauthenticated_user_rejected(): void
    {
        $response = $this->getJson('/portal/stats/bandwidth');

        $response->assertUnauthorized();
    }

    public function test_returns_empty_stats_for_user_with_no_ips(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->mock(NtopNgService::class);

        $response = $this->actingAs($user)->getJson('/portal/stats/bandwidth');

        $response->assertOk()
            ->assertJson([
                'stats' => [],
                'totalReceived' => 0,
                'totalSent' => 0,
            ]);
    }
}
