<?php

namespace Tests\Feature\Admin;

use App\Models\IntegrationConfig;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SettingsControllerIntegrationExpansionTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_integrations_page_returns_all_eight_sections(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/admin/settings/integrations');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Settings/Integrations')
            ->has('integrations.opnsense')
            ->has('integrations.librenms')
            ->has('integrations.ntopng')
            ->has('integrations.pihole')
            ->has('integrations.dhcp')
            ->has('integrations.dns')
            ->has('integrations.auto_allow')
            ->has('integrations.ipv6')
        );
    }

    public function test_can_save_opnsense_expanded_fields(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/settings/integrations', [
            'opnsense' => [
                'endpoint' => 'https://opnsense.example.com',
                'key' => 'testkey',
                'secret' => 'testsecret',
                'captive_portal_id' => '1',
                'verify_ssl' => '1',
                'zone_id' => '0',
                'ratelimit_up_uuid' => 'uuid-up-123',
                'ratelimit_down_uuid' => 'uuid-down-456',
            ],
        ]);

        $response->assertRedirect();
        $this->assertEquals('1', IntegrationConfig::getValue('opnsense', 'verify_ssl'));
        $this->assertEquals('0', IntegrationConfig::getValue('opnsense', 'zone_id'));
        $this->assertEquals('uuid-up-123', IntegrationConfig::getValue('opnsense', 'ratelimit_up_uuid'));
        $this->assertEquals('uuid-down-456', IntegrationConfig::getValue('opnsense', 'ratelimit_down_uuid'));
    }

    public function test_can_save_ntopng_with_username_password(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/settings/integrations', [
            'ntopng' => [
                'endpoint' => 'https://ntopng.example.com',
                'username' => 'admin',
                'password' => 'secret123',
                'interface' => '4',
                'enabled' => '1',
            ],
        ]);

        $response->assertRedirect();
        $this->assertEquals('admin', IntegrationConfig::getValue('ntopng', 'username'));
        $this->assertEquals('4', IntegrationConfig::getValue('ntopng', 'interface'));
        $this->assertEquals('1', IntegrationConfig::getValue('ntopng', 'enabled'));
    }

    public function test_can_save_librenms_enabled_toggle(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/settings/integrations', [
            'librenms' => [
                'endpoint' => 'https://librenms.example.com',
                'api_key' => 'abc123',
                'enabled' => '1',
            ],
        ]);

        $response->assertRedirect();
        $this->assertEquals('1', IntegrationConfig::getValue('librenms', 'enabled'));
    }

    public function test_can_save_pihole_enabled_and_verify_ssl(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/settings/integrations', [
            'pihole' => [
                'endpoint' => 'https://pihole.example.com',
                'password' => 'secret',
                'noblock_group_id' => 2,
                'enabled' => '1',
                'verify_ssl' => '0',
            ],
        ]);

        $response->assertRedirect();
        $this->assertEquals('1', IntegrationConfig::getValue('pihole', 'enabled'));
        $this->assertEquals('0', IntegrationConfig::getValue('pihole', 'verify_ssl'));
    }

    public function test_can_save_dhcp_settings(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/settings/integrations', [
            'dhcp' => [
                'enabled' => '1',
                'endpoint' => 'https://dhcp.example.com',
                'key' => 'dhcpkey',
                'secret' => 'dhcpsecret',
                'verify_ssl' => '1',
                'pool_size' => '254',
            ],
        ]);

        $response->assertRedirect();
        $this->assertEquals('1', IntegrationConfig::getValue('dhcp', 'enabled'));
        $this->assertEquals('https://dhcp.example.com', IntegrationConfig::getValue('dhcp', 'endpoint'));
        $this->assertEquals('254', IntegrationConfig::getValue('dhcp', 'pool_size'));
    }

    public function test_can_save_dns_probe_settings(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/settings/integrations', [
            'dns' => [
                'expected_server' => '10.0.0.1',
                'probe_domain' => 'probe.example.com',
            ],
        ]);

        $response->assertRedirect();
        $this->assertEquals('10.0.0.1', IntegrationConfig::getValue('dns', 'expected_server'));
        $this->assertEquals('probe.example.com', IntegrationConfig::getValue('dns', 'probe_domain'));
    }

    public function test_can_save_auto_allow_settings(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/settings/integrations', [
            'auto_allow' => [
                'enabled' => '1',
                'oui_prefixes' => '98:5F:D3,7C:ED:8D',
                'scan_interval' => '5',
            ],
        ]);

        $response->assertRedirect();
        $this->assertEquals('1', IntegrationConfig::getValue('auto_allow', 'enabled'));
        $this->assertEquals('98:5F:D3,7C:ED:8D', IntegrationConfig::getValue('auto_allow', 'oui_prefixes'));
        $this->assertEquals('5', IntegrationConfig::getValue('auto_allow', 'scan_interval'));
    }

    public function test_can_save_ipv6_detection_settings(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/settings/integrations', [
            'ipv6' => [
                'detection_enabled' => '1',
                'detection_endpoint' => 'https://ipv6.example.com/detect',
            ],
        ]);

        $response->assertRedirect();
        $this->assertEquals('1', IntegrationConfig::getValue('ipv6', 'detection_enabled'));
        $this->assertEquals('https://ipv6.example.com/detect', IntegrationConfig::getValue('ipv6', 'detection_endpoint'));
    }

    public function test_sensitive_fields_are_encrypted(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/settings/integrations', [
            'dhcp' => [
                'key' => 'dhcp-api-key',
                'secret' => 'dhcp-api-secret',
            ],
            'ntopng' => [
                'password' => 'ntopng-pass',
            ],
        ]);

        $response->assertRedirect();

        $dhcpKey = IntegrationConfig::where('integration', 'dhcp')
            ->where('key', 'key')
            ->first();
        $this->assertNotNull($dhcpKey);
        $this->assertTrue($dhcpKey->encrypted, 'dhcp.key should have encrypted=true');
        // Raw DB value should be JSON with encrypted payload (not plaintext)
        $rawValue = $dhcpKey->getRawOriginal('value');
        $decoded = json_decode($rawValue, true);
        $this->assertNotEquals('dhcp-api-key', $decoded['v'] ?? null, 'Raw stored value should be encrypted');
        // But getValue decrypts it
        $this->assertEquals('dhcp-api-key', IntegrationConfig::getValue('dhcp', 'key'));

        $ntopngPassword = IntegrationConfig::where('integration', 'ntopng')
            ->where('key', 'password')
            ->first();
        $this->assertNotNull($ntopngPassword);
        $this->assertTrue($ntopngPassword->encrypted, 'ntopng.password should have encrypted=true');
        $rawNtopng = $ntopngPassword->getRawOriginal('value');
        $decodedNtopng = json_decode($rawNtopng, true);
        $this->assertNotEquals('ntopng-pass', $decodedNtopng['v'] ?? null, 'Raw stored password should be encrypted');
        $this->assertEquals('ntopng-pass', IntegrationConfig::getValue('ntopng', 'password'));
    }

    public function test_opnsense_endpoint_validation_rejects_non_url(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/settings/integrations', [
            'opnsense' => [
                'endpoint' => 'not-a-url',
            ],
        ]);

        $response->assertSessionHasErrors('opnsense.endpoint');
    }

    public function test_dhcp_pool_size_must_be_non_negative_integer(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/settings/integrations', [
            'dhcp' => [
                'pool_size' => '-5',
            ],
        ]);

        $response->assertSessionHasErrors('dhcp.pool_size');
    }

    public function test_auto_allow_scan_interval_must_be_positive(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/settings/integrations', [
            'auto_allow' => [
                'scan_interval' => '0',
            ],
        ]);

        $response->assertSessionHasErrors('auto_allow.scan_interval');
    }

    public function test_ipv6_detection_endpoint_must_be_url(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/settings/integrations', [
            'ipv6' => [
                'detection_endpoint' => 'not-a-url',
            ],
        ]);

        $response->assertSessionHasErrors('ipv6.detection_endpoint');
    }
}
