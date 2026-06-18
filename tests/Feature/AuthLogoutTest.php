<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AuthLogoutTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_logout_destroys_session_and_redirects(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_removed_routes_return_404(): void
    {
        $this->get('/login/discord/redirect')->assertNotFound();
        $this->get('/login/discord/return')->assertNotFound();
        $this->get('/login/check')->assertNotFound();
        $this->post('/auth/device/initiate')->assertNotFound();
        $this->get('/auth/device/poll/test')->assertNotFound();
    }
}
