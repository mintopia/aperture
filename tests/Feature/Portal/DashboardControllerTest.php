<?php

namespace Tests\Feature\Portal;

use App\Models\ContentBlock;
use App\Models\Setting;
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

        $response->assertRedirect('/captive');
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

    public function test_dashboard_passes_dns_detection_when_configured(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        Setting::create(['code' => 'dns.check_url', 'name' => 'DNS Check URL', 'value' => 'https://{uuid}.lancache.test.entropylan.party']);
        Setting::create(['code' => 'dns.warning_message', 'name' => 'DNS Warning Message', 'value' => 'Fix your DNS!']);

        $response = $this->actingAs($user)->get('/portal');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Portal/Dashboard')
            ->has('dnsDetection')
            ->where('dnsDetection.checkUrl', 'https://{uuid}.lancache.test.entropylan.party')
            ->where('dnsDetection.warningMessage', 'Fix your DNS!')
        );
    }

    public function test_dashboard_passes_null_dns_detection_when_not_configured(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/portal');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Portal/Dashboard')
            ->where('dnsDetection', null)
        );
    }

    public function test_dashboard_uses_default_warning_message_when_not_set(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        Setting::create(['code' => 'dns.check_url', 'name' => 'DNS Check URL', 'value' => 'https://{uuid}.lancache.test.entropylan.party']);

        $response = $this->actingAs($user)->get('/portal');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('dnsDetection.checkUrl', 'https://{uuid}.lancache.test.entropylan.party')
            ->where('dnsDetection.warningMessage', 'Your device is not using the event DNS servers. Please update your DNS settings.')
        );
    }
}
