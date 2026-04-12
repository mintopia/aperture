<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PortalControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_creates_ip_and_renders_view(): void
    {
        Queue::fake();
        $user = User::factory()->create(['blocked' => false]);

        $response = $this->actingAs($user)->get('/');
        $response->assertStatus(200);
    }

    public function test_index_does_not_allow_when_user_blocked(): void
    {
        Queue::fake();
        $user = User::factory()->create(['blocked' => true]);

        $response = $this->actingAs($user)->get('/');
        $response->assertStatus(200);
    }

    public function test_status_returns_json_with_ip_info(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/status');
        $response->assertStatus(200);
        $response->assertJsonStructure(['ip', 'allowed']);
    }

    public function test_ipv6_adds_ipv6_address(): void
    {
        Queue::fake();
        $user = User::factory()->create(['blocked' => false]);

        $response = $this->actingAs($user)->postJson('/ipv6', [
            'ipv6' => '2001:db8::1',
        ]);
        $response->assertStatus(200);
        $response->assertJsonStructure(['ip', 'allowed']);
    }

    public function test_ipv6_works_for_blocked_user(): void
    {
        Queue::fake();
        $user = User::factory()->create(['blocked' => true]);

        $response = $this->actingAs($user)->postJson('/ipv6', [
            'ipv6' => '2001:db8::2',
        ]);
        $response->assertStatus(200);
    }

    public function test_unauthenticated_user_redirects_to_login(): void
    {
        $response = $this->get('/');
        $response->assertRedirect(route('login'));
    }
}
