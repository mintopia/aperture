<?php

namespace Tests\Feature;

use App\Models\User;
use App\Providers\RouteServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RedirectIfAuthenticatedTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_is_redirected(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/login');
        $response->assertRedirect(RouteServiceProvider::HOME);
    }

    public function test_guest_can_access_login_page(): void
    {
        $response = $this->get('/login');
        $response->assertStatus(200);
    }
}
