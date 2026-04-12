<?php

namespace Tests\Feature\Portal;

use App\Models\ContentBlock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_sees_dashboard(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/portal');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Portal/Dashboard'));
    }

    public function test_unauthenticated_user_redirected_to_login(): void
    {
        $response = $this->get('/portal');

        $response->assertRedirect('/login');
    }

    public function test_dashboard_includes_active_content_blocks(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        ContentBlock::factory()->create([
            'type' => 'event_info',
            'title' => 'Welcome',
            'is_active' => true,
            'sort_order' => 10,
        ]);

        ContentBlock::factory()->create([
            'type' => 'connection_status',
            'title' => 'Status',
            'is_active' => true,
            'sort_order' => 20,
        ]);

        $response = $this->actingAs($user)->get('/portal');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Portal/Dashboard')
            ->has('blocks', 2)
        );
    }

    public function test_content_blocks_ordered_by_sort_order(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        ContentBlock::factory()->create([
            'type' => 'bandwidth',
            'title' => 'Second',
            'sort_order' => 20,
            'is_active' => true,
        ]);

        ContentBlock::factory()->create([
            'type' => 'event_info',
            'title' => 'First',
            'sort_order' => 10,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->get('/portal');

        $response->assertInertia(fn ($page) => $page
            ->component('Portal/Dashboard')
            ->where('blocks.0.title', 'First')
            ->where('blocks.1.title', 'Second')
        );
    }

    public function test_inactive_blocks_not_shown(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        ContentBlock::factory()->create([
            'type' => 'event_info',
            'title' => 'Active Block',
            'is_active' => true,
        ]);

        ContentBlock::factory()->inactive()->create([
            'type' => 'bandwidth',
            'title' => 'Inactive Block',
        ]);

        $response = $this->actingAs($user)->get('/portal');

        $response->assertInertia(fn ($page) => $page
            ->component('Portal/Dashboard')
            ->has('blocks', 1)
            ->where('blocks.0.title', 'Active Block')
        );
    }

    public function test_dns_warning_block_passes_settings_with_expected_dns(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        ContentBlock::factory()->create([
            'type' => 'dns_warning',
            'title' => 'DNS Check',
            'is_active' => true,
            'sort_order' => 1,
            'settings' => ['expectedDns' => '10.0.0.1'],
        ]);

        $response = $this->actingAs($user)->get('/portal');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Portal/Dashboard')
            ->has('blocks', 1)
            ->where('blocks.0.type', 'dns_warning')
            ->where('blocks.0.settings.expectedDns', '10.0.0.1')
        );
    }
}
