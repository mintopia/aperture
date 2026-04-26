<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NetworkSettingsControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

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

    #[Test]
    public function network_settings_page_loads(): void
    {
        $response = $this->actingAs($this->admin)->get('/admin/settings/network');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page): Assert => $page
            ->component('Admin/Settings/Network')
        );
    }

    #[Test]
    public function network_settings_page_shows_defaults_when_no_settings_exist(): void
    {
        $response = $this->actingAs($this->admin)->get('/admin/settings/network');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page): Assert => $page
            ->where('settings.managed_ranges_v4', '0.0.0.0/0')
            ->where('settings.managed_ranges_v6', '::/0')
            ->where('settings.dns_filter_default', false)
        );
    }

    #[Test]
    public function network_settings_page_shows_stored_values(): void
    {
        Setting::set('network.managed_ranges_v4', 'Managed IPv4 Ranges', json_encode(['10.0.0.0/8', '172.16.0.0/12']));
        Setting::set('network.managed_ranges_v6', 'Managed IPv6 Ranges', json_encode(['fc00::/7']));
        Setting::set('network.dns_filter_default', 'DNS Filter Default', '1');

        $response = $this->actingAs($this->admin)->get('/admin/settings/network');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page): Assert => $page
            ->where('settings.managed_ranges_v4', "10.0.0.0/8\n172.16.0.0/12")
            ->where('settings.managed_ranges_v6', 'fc00::/7')
            ->where('settings.dns_filter_default', true)
        );
    }

    #[Test]
    public function update_saves_valid_ipv4_ranges(): void
    {
        $response = $this->actingAs($this->admin)->put('/admin/settings/network', [
            'managed_ranges_v4' => "10.0.0.0/8\n192.168.0.0/16",
            'managed_ranges_v6' => '::/0',
            'dns_filter_default' => false,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $stored = json_decode((string) Setting::get('network.managed_ranges_v4'), true);
        $this->assertSame(['10.0.0.0/8', '192.168.0.0/16'], $stored);
    }

    #[Test]
    public function update_saves_valid_ipv6_ranges(): void
    {
        $response = $this->actingAs($this->admin)->put('/admin/settings/network', [
            'managed_ranges_v4' => '0.0.0.0/0',
            'managed_ranges_v6' => "fc00::/7\nfe80::/10",
            'dns_filter_default' => false,
        ]);

        $response->assertRedirect();

        $stored = json_decode((string) Setting::get('network.managed_ranges_v6'), true);
        $this->assertSame(['fc00::/7', 'fe80::/10'], $stored);
    }

    #[Test]
    public function update_saves_empty_ranges(): void
    {
        $response = $this->actingAs($this->admin)->put('/admin/settings/network', [
            'managed_ranges_v4' => '',
            'managed_ranges_v6' => '',
            'dns_filter_default' => false,
        ]);

        $response->assertRedirect();

        $stored = json_decode((string) Setting::get('network.managed_ranges_v4'), true);
        $this->assertSame([], $stored);
    }

    #[Test]
    public function update_rejects_invalid_ipv4_cidr(): void
    {
        $response = $this->actingAs($this->admin)->put('/admin/settings/network', [
            'managed_ranges_v4' => "10.0.0.0/8\nnot-a-cidr",
            'managed_ranges_v6' => '::/0',
            'dns_filter_default' => false,
        ]);

        $response->assertSessionHasErrors('managed_ranges_v4');
    }

    #[Test]
    public function update_rejects_invalid_ipv6_cidr(): void
    {
        $response = $this->actingAs($this->admin)->put('/admin/settings/network', [
            'managed_ranges_v4' => '0.0.0.0/0',
            'managed_ranges_v6' => 'zzz::qqq/64',
            'dns_filter_default' => false,
        ]);

        $response->assertSessionHasErrors('managed_ranges_v6');
    }

    #[Test]
    public function update_rejects_ipv6_in_ipv4_field(): void
    {
        $response = $this->actingAs($this->admin)->put('/admin/settings/network', [
            'managed_ranges_v4' => 'fc00::/7',
            'managed_ranges_v6' => '::/0',
            'dns_filter_default' => false,
        ]);

        $response->assertSessionHasErrors('managed_ranges_v4');
    }

    #[Test]
    public function update_rejects_prefix_out_of_range(): void
    {
        $response = $this->actingAs($this->admin)->put('/admin/settings/network', [
            'managed_ranges_v4' => '10.0.0.0/33',
            'managed_ranges_v6' => '::/0',
            'dns_filter_default' => false,
        ]);

        $response->assertSessionHasErrors('managed_ranges_v4');
    }

    #[Test]
    public function update_saves_dns_filter_default(): void
    {
        $response = $this->actingAs($this->admin)->put('/admin/settings/network', [
            'managed_ranges_v4' => '0.0.0.0/0',
            'managed_ranges_v6' => '::/0',
            'dns_filter_default' => true,
        ]);

        $response->assertRedirect();
        $this->assertSame('1', Setting::get('network.dns_filter_default'));
    }

    #[Test]
    public function update_rejects_ipv4_in_ipv6_field(): void
    {
        $response = $this->actingAs($this->admin)->put('/admin/settings/network', [
            'managed_ranges_v4' => '0.0.0.0/0',
            'managed_ranges_v6' => '10.0.0.0/8',
            'dns_filter_default' => false,
        ]);

        $response->assertSessionHasErrors('managed_ranges_v6');
    }

    #[Test]
    public function non_admin_cannot_access_network_settings(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin/settings/network')->assertForbidden();
        $this->actingAs($user)->put('/admin/settings/network', [
            'managed_ranges_v4' => '0.0.0.0/0',
            'managed_ranges_v6' => '::/0',
            'dns_filter_default' => false,
        ])->assertForbidden();
    }
}
