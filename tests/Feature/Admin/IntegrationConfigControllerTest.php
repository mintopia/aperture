<?php

namespace Tests\Feature\Admin;

use App\Models\IntegrationConfig;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IntegrationConfigControllerTest extends TestCase
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

    public function test_admin_can_save_opnsense_config(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/settings/integrations', [
            'opnsense' => [
                'endpoint' => 'https://opnsense.local',
                'key' => 'test-api-key',
                'secret' => 'test-api-secret',
                'captive_portal_id' => '1',
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertEquals('https://opnsense.local', IntegrationConfig::getValue('opnsense', 'endpoint'));
        $this->assertEquals('test-api-key', IntegrationConfig::getValue('opnsense', 'key'));
        $this->assertEquals('test-api-secret', IntegrationConfig::getValue('opnsense', 'secret'));
        $this->assertEquals('1', IntegrationConfig::getValue('opnsense', 'captive_portal_id'));
    }

    public function test_admin_can_save_librenms_config(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/settings/integrations', [
            'librenms' => [
                'endpoint' => 'https://librenms.local',
                'api_key' => 'librenms-api-key-123',
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertEquals('https://librenms.local', IntegrationConfig::getValue('librenms', 'endpoint'));
        $this->assertEquals('librenms-api-key-123', IntegrationConfig::getValue('librenms', 'api_key'));
    }

    public function test_admin_can_save_pihole_config(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/settings/integrations', [
            'pihole' => [
                'endpoint' => 'https://pihole.local',
                'password' => 'pihole-pass',
                'noblock_group_id' => 5,
            ],
        ]);

        $response->assertRedirect();

        $this->assertEquals('https://pihole.local', IntegrationConfig::getValue('pihole', 'endpoint'));
        $this->assertEquals('pihole-pass', IntegrationConfig::getValue('pihole', 'password'));
        $this->assertEquals('5', IntegrationConfig::getValue('pihole', 'noblock_group_id'));
    }

    public function test_sensitive_fields_are_stored_encrypted(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $this->actingAs($admin)->put('/admin/settings/integrations', [
            'librenms' => [
                'api_key' => 'my-secret-api-key',
            ],
        ]);

        $rawConfig = IntegrationConfig::where('integration', 'librenms')
            ->where('key', 'api_key')
            ->first();

        $this->assertTrue($rawConfig->encrypted);
        $this->assertNotEquals('my-secret-api-key', $rawConfig->getRawOriginal('value'));
        $this->assertEquals('my-secret-api-key', IntegrationConfig::getValue('librenms', 'api_key'));
    }

    public function test_non_admin_cannot_update_integrations(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->put('/admin/settings/integrations', [
            'opnsense' => ['endpoint' => 'https://opnsense.local'],
        ]);

        $response->assertForbidden();
    }
}
