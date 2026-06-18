<?php

declare(strict_types=1);

namespace Tests\Feature\Middleware;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EnsureSetupCompleteTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected bool $seedSetupUser = false;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_middleware_redirects_to_setup_when_no_users(): void
    {
        $response = $this->get('/login');

        $response->assertRedirect('/setup');
    }

    public function test_middleware_allows_request_when_users_exist(): void
    {
        User::factory()->create();

        $response = $this->get('/login');

        $response->assertOk();
    }

    public function test_middleware_returns_503_for_api_when_no_users(): void
    {
        $response = $this->getJson('/api/captive-portal');

        $response->assertStatus(503);
        $response->assertJson([
            'message' => 'Application setup is not complete.',
        ]);
    }

    public function test_setup_routes_excluded_from_middleware(): void
    {
        $response = $this->get('/setup');

        $response->assertOk();
    }
}
