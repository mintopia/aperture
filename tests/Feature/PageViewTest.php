<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PageViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_page_can_be_viewed(): void
    {
        Queue::fake();
        Page::factory()->terms()->create();

        $response = $this->get('/content/terms');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Content/Show')
            ->has('page')
        );
    }

    public function test_public_page_returns_404_for_missing_slug(): void
    {
        Queue::fake();

        $response = $this->get('/content/nonexistent');

        $response->assertNotFound();
    }

    public function test_public_page_accessible_while_logged_out(): void
    {
        Queue::fake();
        Page::factory()->terms()->create();

        $response = $this->get('/content/terms');

        $response->assertOk();
    }

    public function test_public_page_accessible_while_logged_in(): void
    {
        Queue::fake();
        Page::factory()->terms()->create();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/content/terms');

        $response->assertOk();
    }
}
