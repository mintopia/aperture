<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ErrorPageTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_404_error_renders_inertia_error_page(): void
    {
        $response = $this->get('/this-route-definitely-does-not-exist');

        $response->assertStatus(404);
        $response->assertInertia(fn ($page) => $page
            ->component('Error')
            ->where('status', 404)
        );
    }

    public function test_403_error_renders_inertia_error_page(): void
    {
        // Access an admin route without authentication via an Inertia request.
        // The middleware returns a 409 with X-Inertia-Location to force a full
        // page visit to the login route instead of embedding it in the layout.
        $response = $this->get('/admin', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => '1',
        ]);

        $response->assertStatus(409);
        $response->assertHeader('X-Inertia-Location', route('login'));
    }

    public function test_error_page_works_without_authentication(): void
    {
        // Ensure error pages render even when no user is logged in
        $response = $this->get('/this-route-definitely-does-not-exist');

        $response->assertStatus(404);
        $response->assertInertia(fn ($page) => $page
            ->component('Error')
            ->has('status')
        );
    }

    public function test_error_response_includes_correct_status_code(): void
    {
        $response = $this->get('/this-route-definitely-does-not-exist');

        $response->assertStatus(404);
        $response->assertInertia(fn ($page) => $page
            ->where('status', 404)
        );
    }
}
