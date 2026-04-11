<?php

namespace Tests\Feature\Portal;

use App\Models\IpAddress;
use App\Models\User;
use App\Models\UserIpAddress;
use App\Services\Interfaces\DnsBlockingInterface;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PiHoleControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function createUserWithIp(User $user, string $ip): IpAddress
    {
        $ipAddress = new IpAddress;
        $ipAddress->address = $ip;
        $ipAddress->last_seen_at = Carbon::now();
        $ipAddress->save();

        $userIp = new UserIpAddress;
        $userIp->user()->associate($user);
        $userIp->ip()->associate($ipAddress);
        $userIp->last_seen_at = Carbon::now();
        $userIp->save();

        return $ipAddress;
    }

    public function test_toggle_enables_pihole_for_users_ip(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $this->createUserWithIp($user, '127.0.0.1');

        $mock = $this->mock(DnsBlockingInterface::class);
        $mock->shouldReceive('isEnabledForIp')->with('127.0.0.1')->once()->andReturn(false);
        $mock->shouldReceive('enableForIp')->with('127.0.0.1')->once();

        $response = $this->actingAs($user)->postJson('/portal/pihole/toggle');

        $response->assertOk()
            ->assertJson(['enabled' => true]);
    }

    public function test_toggle_disables_pihole_for_users_ip(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $this->createUserWithIp($user, '127.0.0.1');

        $mock = $this->mock(DnsBlockingInterface::class);
        $mock->shouldReceive('isEnabledForIp')->with('127.0.0.1')->once()->andReturn(true);
        $mock->shouldReceive('disableForIp')->with('127.0.0.1')->once();

        $response = $this->actingAs($user)->postJson('/portal/pihole/toggle');

        $response->assertOk()
            ->assertJson(['enabled' => false]);
    }

    public function test_rejects_toggle_for_ip_not_owned_by_user(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        // User has no IPs associated

        $mock = $this->mock(DnsBlockingInterface::class);
        $mock->shouldNotReceive('isEnabledForIp');

        $response = $this->actingAs($user)->postJson('/portal/pihole/toggle');

        $response->assertForbidden();
    }

    public function test_unauthenticated_user_rejected(): void
    {
        $response = $this->postJson('/portal/pihole/toggle');

        $response->assertUnauthorized();
    }
}
