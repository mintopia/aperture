<?php

namespace Tests\Feature\Portal;

use App\Models\ContentBlock;
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserParameter;
use App\Services\Interfaces\NetworkInventoryInterface;
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

        // Default mock for NetworkInventoryInterface — returns no IPv6 neighbors.
        // Individual tests can override by re-binding.
        $mock = $this->createMock(NetworkInventoryInterface::class);
        $mock->method('getIpv6Neighbors')->willReturn(collect());
        $this->app->instance(NetworkInventoryInterface::class, $mock);
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
        $user = User::factory()->create(['nickname' => 'Player1']);

        // Pre-create an IP with a known address, allowed, and associate a MAC
        $mac = MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:FF']);
        IpAddress::factory()->allowed()->create([
            'address' => '10.0.0.1',
            'mac_address_id' => $mac->id,
        ]);

        // Create a user parameter
        UserParameter::factory()->create(['user_id' => $user->id, 'key' => 'seat', 'value' => 'A42']);

        // Mock NetworkInventoryInterface to return an IPv6 neighbor matching the MAC
        $mockInventory = $this->createMock(NetworkInventoryInterface::class);
        $mockInventory->method('getIpv6Neighbors')->willReturn(collect([
            new ArpEntry(ip: 'fe80::1', mac: 'AA:BB:CC:DD:EE:FF'),
        ]));
        $this->app->instance(NetworkInventoryInterface::class, $mockInventory);

        $response = $this->actingAs($user)
            ->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->get('/portal');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('blockContext')
            ->where('blockContext.currentIpv4', '10.0.0.1')
            ->where('blockContext.currentIpv6', 'fe80::1')
            ->where('blockContext.ipAllowed', true)
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
            ->has('blockContext.ipAllowed')
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
}
