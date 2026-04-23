<?php

declare(strict_types=1);

namespace Tests\Feature\NetworkDeviceTracking;

use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OuiConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->admin = $this->createAdminUser();
    }

    protected function createAdminUser(): User
    {
        $user = User::factory()->create();
        $role = new Role;
        $role->code = 'admin';
        $role->name = 'Admin';
        $role->save();
        $user->roles()->attach($role);

        return $user;
    }

    public function test_network_settings_shows_oui_field(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.settings.network'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page): Assert => $page
            ->component('Admin/Settings/Network')
            ->has('settings.oui_auto_allow')
        );
    }

    public function test_valid_oui_prefixes_save_correctly(): void
    {
        $response = $this->actingAs($this->admin)->put(route('admin.settings.network.update'), [
            'managed_ranges_v4' => '0.0.0.0/0',
            'managed_ranges_v6' => '::/0',
            'dns_filter_default' => false,
            'oui_auto_allow' => "AA:BB:CC\n00:50:F2",
        ]);

        $response->assertRedirect();

        $raw = Setting::get('network.oui_auto_allow');
        $this->assertEquals(['AA:BB:CC', '00:50:F2'], json_decode((string) $raw, true));
    }

    public function test_invalid_oui_format_rejected(): void
    {
        $response = $this->actingAs($this->admin)->put(route('admin.settings.network.update'), [
            'managed_ranges_v4' => '0.0.0.0/0',
            'managed_ranges_v6' => '::/0',
            'dns_filter_default' => false,
            'oui_auto_allow' => 'ZZZZ',
        ]);

        $response->assertSessionHasErrors('oui_auto_allow');
    }

    public function test_empty_oui_clears_setting(): void
    {
        Setting::set('network.oui_auto_allow', 'OUI Auto-Allow Prefixes', json_encode(['AA:BB:CC']));

        $response = $this->actingAs($this->admin)->put(route('admin.settings.network.update'), [
            'managed_ranges_v4' => '0.0.0.0/0',
            'managed_ranges_v6' => '::/0',
            'dns_filter_default' => false,
            'oui_auto_allow' => '',
        ]);

        $response->assertRedirect();

        $raw = Setting::get('network.oui_auto_allow');
        $this->assertEquals([], json_decode((string) $raw, true));
    }

    public function test_single_octet_oui_accepted(): void
    {
        $response = $this->actingAs($this->admin)->put(route('admin.settings.network.update'), [
            'managed_ranges_v4' => '0.0.0.0/0',
            'managed_ranges_v6' => '::/0',
            'dns_filter_default' => false,
            'oui_auto_allow' => 'AA',
        ]);

        $response->assertRedirect();
    }

    public function test_six_octet_full_mac_oui_accepted(): void
    {
        $response = $this->actingAs($this->admin)->put(route('admin.settings.network.update'), [
            'managed_ranges_v4' => '0.0.0.0/0',
            'managed_ranges_v6' => '::/0',
            'dns_filter_default' => false,
            'oui_auto_allow' => 'AA:BB:CC:DD:EE:FF',
        ]);

        $response->assertRedirect();
    }
}
