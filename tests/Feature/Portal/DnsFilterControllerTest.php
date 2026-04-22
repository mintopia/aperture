<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DnsFilterControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_toggle_disables_dns_filtering_when_currently_enabled(): void
    {
        Queue::fake();
        $user = User::factory()->create(['dns_filtering_enabled' => true]);

        $response = $this->actingAs($user)->postJson('/portal/dns-filter/toggle');

        $response->assertOk()
            ->assertJson(['enabled' => false]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'dns_filtering_enabled' => false,
        ]);
    }

    public function test_toggle_enables_dns_filtering_when_currently_disabled(): void
    {
        Queue::fake();
        $user = User::factory()->create(['dns_filtering_enabled' => false]);

        $response = $this->actingAs($user)->postJson('/portal/dns-filter/toggle');

        $response->assertOk()
            ->assertJson(['enabled' => true]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'dns_filtering_enabled' => true,
        ]);
    }

    public function test_unauthenticated_user_rejected(): void
    {
        $response = $this->postJson('/portal/dns-filter/toggle');

        $response->assertUnauthorized();
    }
}
