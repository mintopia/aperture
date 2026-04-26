<?php

namespace Tests\Feature\Portal;

use App\Models\ContentBlock;
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserParameter;
use App\Services\LibreNms\LibreNmsService;
use App\Services\ValueObjects\ArpEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Default mock for LibreNmsService — returns no IPv6 neighbors.
        // Individual tests can override by re-binding.
        $mock = $this->createMock(LibreNmsService::class);
        $mock->method('getIpv6Neighbors')->willReturn(collect());
        $this->app->instance(LibreNmsService::class, $mock);
    }

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

        ContentBlock::factory()->atPosition(1, 1)->create([
            'type' => 'custom_markdown',
            'title' => 'Welcome',
            'is_active' => true,
        ]);

        ContentBlock::factory()->atPosition(2, 1)->create([
            'type' => 'connection_strip',
            'title' => 'Status',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->get('/portal');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Portal/Dashboard')
            ->has('blocks', 2)
        );
    }

    public function test_content_blocks_ordered_by_grid_position(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        ContentBlock::factory()->atPosition(1, 2)->create([
            'type' => 'bandwidth',
            'title' => 'Second',
            'is_active' => true,
        ]);

        ContentBlock::factory()->atPosition(1, 1)->create([
            'type' => 'custom_markdown',
            'title' => 'First',
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
            'type' => 'custom_markdown',
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

    public function test_dashboard_passes_block_context_with_mac_and_user_params(): void
    {
        Queue::fake();
        $user = User::factory()->create(['nickname' => 'Player1', 'internet_enabled' => true]);

        // Pre-create an IP with a known address, and associate a MAC via pivot
        $mac = MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:FF']);
        $ip = IpAddress::factory()->create([
            'address' => '10.0.0.1',
        ]);
        $ip->macAddresses()->attach($mac, ['source' => 'auth', 'last_seen_at' => now()]);

        // Create a user parameter
        UserParameter::factory()->create(['user_id' => $user->id, 'key' => 'seat', 'value' => 'A42']);

        // Mock LibreNmsService to return an IPv6 neighbor matching the MAC
        $mockInventory = $this->createMock(LibreNmsService::class);
        $mockInventory->method('getIpv6Neighbors')->willReturn(collect([
            new ArpEntry(ip: 'fe80::1', mac: 'AA:BB:CC:DD:EE:FF'),
        ]));
        $this->app->instance(LibreNmsService::class, $mockInventory);

        $response = $this->actingAs($user)
            ->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->get('/portal');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('blockContext')
            ->where('blockContext.currentIpv4', '10.0.0.1')
            ->where('blockContext.currentIpv6', 'fe80::1')
            ->where('blockContext.internetEnabled', true)
            ->where('blockContext.internetBlocked', false)
            ->where('blockContext.blockedMessage', '')
            ->where('blockContext.macAddress', 'AA:BB:CC:DD:EE:FF')
            ->has('blockContext.user')
            ->where('blockContext.user.name', 'Player1')
            ->where('blockContext.user.params.seat', 'A42')
        );
    }

    public function test_dashboard_block_context_without_mac_address(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/portal');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('blockContext')
            ->where('blockContext.macAddress', null)
            ->where('blockContext.currentIpv6', '')
            ->has('blockContext.currentIpv4')
            ->has('blockContext.internetEnabled')
            ->has('blockContext.internetBlocked')
            ->has('blockContext.blockedMessage')
        );
    }

    public function test_dashboard_block_context_user_empty_when_no_parameters(): void
    {
        Queue::fake();
        $user = User::factory()->create(['nickname' => 'TestUser']);

        $response = $this->actingAs($user)->get('/portal');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('blockContext')
            ->where('blockContext.user.name', 'TestUser')
            ->where('blockContext.user.params', [])
        );
    }

    public function test_dashboard_block_context_includes_dns_filtering_enabled(): void
    {
        Queue::fake();
        $user = User::factory()->create(['dns_filtering_enabled' => true]);

        $response = $this->actingAs($user)->get('/portal');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('blockContext')
            ->where('blockContext.dnsFilteringEnabled', true)
        );
    }

    public function test_dashboard_block_context_dns_filtering_disabled(): void
    {
        Queue::fake();
        $user = User::factory()->create(['dns_filtering_enabled' => false]);

        $response = $this->actingAs($user)->get('/portal');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('blockContext')
            ->where('blockContext.dnsFilteringEnabled', false)
        );
    }

    public function test_dashboard_block_context_internet_blocked_when_user_internet_blocked(): void
    {
        Queue::fake();
        $user = User::factory()->internetBlocked()->create();

        $response = $this->actingAs($user)->get('/portal');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('blockContext')
            ->where('blockContext.internetBlocked', true)
            ->where('blockContext.internetEnabled', false)
        );
    }

    public function test_dashboard_block_context_not_internet_blocked_for_normal_user(): void
    {
        Queue::fake();
        $user = User::factory()->create(['internet_blocked' => false, 'internet_enabled' => true]);

        $response = $this->actingAs($user)->get('/portal');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('blockContext')
            ->where('blockContext.internetBlocked', false)
        );
    }

    public function test_dashboard_block_context_includes_blocked_message_from_setting(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        Setting::create(['code' => 'portal.blocked_message', 'name' => 'Blocked Message', 'value' => 'You are blocked.']);

        $response = $this->actingAs($user)->get('/portal');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('blockContext')
            ->where('blockContext.blockedMessage', 'You are blocked.')
        );
    }

    public function test_dashboard_block_context_blocked_message_defaults_to_empty_string(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/portal');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('blockContext')
            ->where('blockContext.blockedMessage', '')
        );
    }

    public function test_dashboard_renders_with_null_ip_when_outside_managed_range(): void
    {
        Queue::fake();
        Setting::set('network.managed_ranges_v4', 'Managed IPv4 Ranges', json_encode(['172.16.0.0/12']));

        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
            ->get('/portal');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Portal/Dashboard')
            ->has('blockContext')
            ->where('blockContext.currentIpv4', '203.0.113.50')
            ->where('blockContext.currentIpv6', null)
            ->where('blockContext.internetEnabled', false)
            ->where('blockContext.macAddress', null)
        );
    }
}
