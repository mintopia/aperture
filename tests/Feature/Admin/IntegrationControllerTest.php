<?php

namespace Tests\Feature\Admin;

use App\Models\CapabilityAssignment;
use App\Models\ConnectionTestLog;
use App\Models\IntegrationConfig;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IntegrationControllerTest extends TestCase
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

    public function test_can_view_integration_show_page(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        IntegrationConfig::setValue('opnsense', 'endpoint', 'https://opnsense.example.com');
        CapabilityAssignment::assign('dhcp', 'opnsense');
        ConnectionTestLog::record('opnsense', true, 'Connected successfully');

        $response = $this->actingAs($admin)->get('/admin/settings/integrations/opnsense');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Settings/IntegrationShow')
            ->where('service.id', 'opnsense')
            ->where('service.config.endpoint', 'https://opnsense.example.com')
            ->has('service.capabilities')
            ->has('service.logs')
        );
    }

    public function test_show_returns_404_for_invalid_service(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/admin/settings/integrations/invalid');

        $response->assertNotFound();
    }

    public function test_can_update_integration_config(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/settings/integrations/opnsense', [
            'config' => [
                'endpoint' => 'https://opnsense.example.com',
                'key' => 'test-key',
                'secret' => 'test-secret',
                'verify_ssl' => '1',
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertEquals('https://opnsense.example.com', IntegrationConfig::getValue('opnsense', 'endpoint'));
        $this->assertEquals('test-key', IntegrationConfig::getValue('opnsense', 'key'));
        $this->assertEquals('test-secret', IntegrationConfig::getValue('opnsense', 'secret'));
        $this->assertEquals('1', IntegrationConfig::getValue('opnsense', 'verify_ssl'));
    }

    public function test_can_toggle_capability(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->putJson('/admin/settings/capabilities', [
            'capability' => 'dhcp',
            'integration' => 'opnsense',
            'active' => true,
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);
        $this->assertDatabaseHas('capability_assignments', [
            'capability' => 'dhcp',
            'integration' => 'opnsense',
        ]);
    }

    public function test_can_deactivate_capability(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        CapabilityAssignment::assign('dhcp', 'opnsense');

        $response = $this->actingAs($admin)->putJson('/admin/settings/capabilities', [
            'capability' => 'dhcp',
            'integration' => 'opnsense',
            'active' => false,
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);
        $this->assertDatabaseMissing('capability_assignments', [
            'capability' => 'dhcp',
        ]);
    }

    public function test_capability_toggle_validates_integration_supports_capability(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->putJson('/admin/settings/capabilities', [
            'capability' => 'dhcp',
            'integration' => 'librenms',
            'active' => true,
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'message' => 'Integration librenms does not support capability dhcp.',
        ]);
    }

    public function test_can_get_health_log(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        ConnectionTestLog::record('opnsense', true, 'Connected successfully');
        ConnectionTestLog::record('opnsense', false, 'Request failed');

        $response = $this->actingAs($admin)->getJson('/admin/settings/integrations/opnsense/health-log');

        $response->assertOk();
        $response->assertJsonCount(2, 'logs');
        $response->assertJsonStructure([
            'logs' => [
                '*' => ['id', 'success', 'message', 'tested_at'],
            ],
        ]);
    }

    public function test_non_admin_cannot_access_integration(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/admin/settings/integrations/opnsense');

        $response->assertForbidden();
    }

    public function test_admin_can_fetch_pihole_groups(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        Http::fake([
            '*/api/auth' => Http::response(['session' => ['token' => 'test-token', 'validity' => 300]], 200),
            '*/api/groups' => Http::response(['groups' => [
                ['id' => 0, 'name' => 'Default', 'enabled' => true],
                ['id' => 1, 'name' => 'Ad Blocking', 'enabled' => true],
            ]], 200),
        ]);

        $response = $this->actingAs($admin)->postJson('/admin/settings/integrations/pihole/groups', [
            'endpoint' => 'https://pihole.test',
            'password' => 'test',
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['groups']);
        $response->assertJsonCount(2, 'groups');
        $response->assertJsonPath('groups.0.id', 0);
        $response->assertJsonPath('groups.0.name', 'Default');
        $response->assertJsonPath('groups.1.id', 1);
        $response->assertJsonPath('groups.1.name', 'Ad Blocking');
    }

    public function test_pihole_groups_returns_error_on_failure(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        Http::fake([
            '*' => Http::response('Server Error', 500),
        ]);

        $response = $this->actingAs($admin)->postJson('/admin/settings/integrations/pihole/groups', [
            'endpoint' => 'https://pihole.test',
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['groups', 'error']);
        $response->assertJsonPath('groups', []);
    }

    public function test_pihole_groups_uses_request_values_over_db(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        IntegrationConfig::setValue('pihole', 'endpoint', 'https://old-pihole.example.com');
        IntegrationConfig::setValue('pihole', 'password', 'old-password', true);

        Http::fake([
            '*/api/auth' => Http::response(['session' => ['token' => 'tok', 'validity' => 300]], 200),
            '*/api/groups' => Http::response(['groups' => [
                ['id' => 0, 'name' => 'Default', 'enabled' => true],
            ]], 200),
        ]);

        $response = $this->actingAs($admin)->postJson('/admin/settings/integrations/pihole/groups', [
            'endpoint' => 'https://new-pihole.example.com',
            'password' => 'new-password',
        ]);

        $response->assertOk();
        $response->assertJsonCount(1, 'groups');
        Http::assertSent(fn ($req) => str_contains($req->url(), 'new-pihole.example.com'));
    }

    public function test_pihole_groups_falls_back_to_db_config(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        IntegrationConfig::setValue('pihole', 'endpoint', 'https://db-pihole.example.com');
        IntegrationConfig::setValue('pihole', 'password', 'db-password', true);

        Http::fake([
            '*/api/auth' => Http::response(['session' => ['token' => 'tok', 'validity' => 300]], 200),
            '*/api/groups' => Http::response(['groups' => [
                ['id' => 0, 'name' => 'Default', 'enabled' => true],
            ]], 200),
        ]);

        $response = $this->actingAs($admin)->postJson('/admin/settings/integrations/pihole/groups');

        $response->assertOk();
        $response->assertJsonCount(1, 'groups');
        Http::assertSent(fn ($req) => str_contains($req->url(), 'db-pihole.example.com'));
    }

    public function test_non_admin_cannot_fetch_pihole_groups(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/admin/settings/integrations/pihole/groups');

        $response->assertForbidden();
    }

    public function test_pihole_groups_sends_auth_token_to_groups_endpoint(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        Http::fake([
            '*/api/auth' => Http::response(['session' => ['token' => 'my-secret-token', 'validity' => 300]], 200),
            '*/api/groups' => Http::response(['groups' => []], 200),
        ]);

        $this->actingAs($admin)->postJson('/admin/settings/integrations/pihole/groups', [
            'endpoint' => 'https://pihole.test',
            'password' => 'pass',
        ]);

        Http::assertSent(function ($req) {
            if (str_contains($req->url(), '/api/groups')) {
                return $req->header('Authorization') === ['Token my-secret-token'];
            }

            return true;
        });
    }

    public function test_pihole_show_page_includes_remote_field_metadata(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/admin/settings/integrations/pihole');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Settings/IntegrationShow')
            ->where('service.id', 'pihole')
            ->has('service.fields')
        );

        $serviceData = $response->original->getData()['page']['props']['service'];
        $noblockField = collect($serviceData['fields'])->firstWhere('key', 'noblock_group_id');
        $this->assertNotNull($noblockField);
        $this->assertEquals('select-remote', $noblockField['type']);
        $this->assertEquals('/admin/settings/integrations/pihole/groups', $noblockField['remote_url']);
        $this->assertEquals('name', $noblockField['remote_label']);
        $this->assertEquals('id', $noblockField['remote_value']);
    }
}
