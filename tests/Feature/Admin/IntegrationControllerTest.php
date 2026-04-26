<?php

namespace Tests\Feature\Admin;

use App\Models\CapabilityAssignment;
use App\Models\ConnectionTestLog;
use App\Models\IntegrationConfig;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IntegrationControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

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
        ConnectionTestLog::record('opnsense', true, 'Connected successfully', null, '{"status":"ok"}', 'GET', 'https://opnsense.local/api/diagnostics/system/system_time', 200);
        ConnectionTestLog::record('opnsense', false, 'Request failed', null, null, 'GET', 'https://opnsense.local/api/diagnostics/system/system_time');

        $response = $this->actingAs($admin)->getJson('/admin/settings/integrations/opnsense/health-log');

        $response->assertOk();
        $response->assertJsonCount(2, 'logs');
        $response->assertJsonStructure([
            'logs' => [
                '*' => ['id', 'success', 'message', 'request_method', 'request_url', 'response_status', 'tested_at'],
            ],
        ]);
        $response->assertJsonPath('logs.0.request_method', 'GET');
        $response->assertJsonPath('logs.0.response_status', 200);
    }

    public function test_update_skips_keys_not_in_validation_rules(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        // Send a config key ('unknown_key') that does not exist in the opnsense
        // validationRules, triggering the `continue` branch on line 136.
        $response = $this->actingAs($admin)->put('/admin/settings/integrations/opnsense', [
            'config' => [
                'endpoint' => 'https://opnsense.example.com',
                'unknown_key' => 'should-be-ignored',
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        // The valid key was stored, the unknown key was not
        $this->assertEquals('https://opnsense.example.com', IntegrationConfig::getValue('opnsense', 'endpoint'));
        $this->assertNull(IntegrationConfig::getValue('opnsense', 'unknown_key'));
    }

    public function test_integer_config_values_are_stored_as_integers(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/settings/integrations/pihole', [
            'config' => [
                'endpoint' => 'https://pihole.test',
                'password' => 'secret',
                'filtered_group_id' => '3',
                'enabled' => '1',
                'verify_ssl' => '1',
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $stored = IntegrationConfig::getValue('pihole', 'filtered_group_id');
        $this->assertSame(3, $stored);
        $this->assertIsInt($stored);
    }

    public function test_null_integer_config_values_remain_null(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/settings/integrations/pihole', [
            'config' => [
                'endpoint' => 'https://pihole.test',
                'password' => 'secret',
                'filtered_group_id' => null,
                'enabled' => '1',
                'verify_ssl' => '1',
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $stored = IntegrationConfig::getValue('pihole', 'filtered_group_id');
        $this->assertNull($stored);
    }

    public function test_numeric_config_values_are_stored_with_correct_type(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/settings/integrations/prometheus', [
            'config' => [
                'endpoint' => 'https://prometheus.test',
                'bearer_token' => 'tok',
                'verify_ssl' => '1',
                'default_step' => '60',
                'enabled' => '1',
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $stored = IntegrationConfig::getValue('prometheus', 'default_step');
        $this->assertSame(60, $stored);
        $this->assertIsInt($stored);
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
            '*/api/auth' => Http::response(['session' => ['sid' => 'test-sid', 'validity' => 300]], 200),
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

    public function test_pihole_groups_returns_error_on_auth_failure(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        Http::fake([
            '*/api/auth' => Http::response(['error' => ['key' => 'unauthorized']], 401),
        ]);

        $response = $this->actingAs($admin)->postJson('/admin/settings/integrations/pihole/groups', [
            'endpoint' => 'https://pihole.test',
            'password' => 'wrong-password',
        ]);

        $response->assertOk();
        $response->assertJsonPath('groups', []);
        $this->assertStringContainsString('authentication failed', $response->json('error'));
    }

    public function test_pihole_groups_returns_error_on_groups_failure(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        Http::fake([
            '*/api/auth' => Http::response(['session' => ['sid' => 'tok', 'validity' => 300]], 200),
            '*/api/groups' => Http::response('Forbidden', 403),
        ]);

        $response = $this->actingAs($admin)->postJson('/admin/settings/integrations/pihole/groups', [
            'endpoint' => 'https://pihole.test',
            'password' => 'test',
        ]);

        $response->assertOk();
        $response->assertJsonPath('groups', []);
        $this->assertStringContainsString('Failed to fetch Pi-hole groups', $response->json('error'));
    }

    public function test_pihole_groups_returns_error_when_no_endpoint(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->postJson('/admin/settings/integrations/pihole/groups', [
            'password' => 'test',
        ]);

        $response->assertOk();
        $response->assertJsonPath('groups', []);
        $this->assertStringContainsString('endpoint is not configured', $response->json('error'));
    }

    public function test_pihole_groups_returns_error_when_no_password(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->postJson('/admin/settings/integrations/pihole/groups', [
            'endpoint' => 'https://pihole.test',
        ]);

        $response->assertOk();
        $response->assertJsonPath('groups', []);
        $this->assertStringContainsString('password is not configured', $response->json('error'));
    }

    public function test_pihole_groups_returns_error_when_sid_missing_from_auth_response(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        Http::fake([
            '*/api/auth' => Http::response(['session' => ['validity' => 300]], 200),
        ]);

        $response = $this->actingAs($admin)->postJson('/admin/settings/integrations/pihole/groups', [
            'endpoint' => 'https://pihole.test',
            'password' => 'test',
        ]);

        $response->assertOk();
        $response->assertJsonPath('groups', []);
        $this->assertStringContainsString('no session ID', $response->json('error'));
    }

    public function test_pihole_groups_sends_sid_header_to_groups_endpoint(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        Http::fake([
            '*/api/auth' => Http::response(['session' => ['sid' => 'my-session-id', 'validity' => 300]], 200),
            '*/api/groups' => Http::response(['groups' => [
                ['id' => 0, 'name' => 'Default', 'enabled' => true],
            ]], 200),
        ]);

        $response = $this->actingAs($admin)->postJson('/admin/settings/integrations/pihole/groups', [
            'endpoint' => 'https://pihole.test',
            'password' => 'test',
        ]);

        $response->assertOk();
        $response->assertJsonCount(1, 'groups');

        Http::assertSent(function ($req): bool {
            if (str_contains($req->url(), '/api/groups')) {
                return $req->header('X-FTL-SID') === ['my-session-id'];
            }

            return true;
        });
    }

    public function test_pihole_groups_sends_json_content_type_for_auth(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        Http::fake([
            '*/api/auth' => Http::response(['session' => ['sid' => 'tok', 'validity' => 300]], 200),
            '*/api/groups' => Http::response(['groups' => []], 200),
        ]);

        $this->actingAs($admin)->postJson('/admin/settings/integrations/pihole/groups', [
            'endpoint' => 'https://pihole.test',
            'password' => 'test',
        ]);

        Http::assertSent(function ($req): bool {
            if (str_contains($req->url(), '/api/auth')) {
                return $req->header('Content-Type')[0] === 'application/json';
            }

            return true;
        });
    }

    public function test_pihole_groups_uses_request_values_over_db(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        IntegrationConfig::setValue('pihole', 'endpoint', 'https://old-pihole.example.com');
        IntegrationConfig::setValue('pihole', 'password', 'old-password', true);

        Http::fake([
            '*/api/auth' => Http::response(['session' => ['sid' => 'tok', 'validity' => 300]], 200),
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
        Http::assertSent(fn ($req): bool => str_contains($req->url(), 'new-pihole.example.com'));
    }

    public function test_pihole_groups_falls_back_to_db_config(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        IntegrationConfig::setValue('pihole', 'endpoint', 'https://db-pihole.example.com');
        IntegrationConfig::setValue('pihole', 'password', 'db-password', true);

        Http::fake([
            '*/api/auth' => Http::response(['session' => ['sid' => 'tok', 'validity' => 300]], 200),
            '*/api/groups' => Http::response(['groups' => [
                ['id' => 0, 'name' => 'Default', 'enabled' => true],
            ]], 200),
        ]);

        $response = $this->actingAs($admin)->postJson('/admin/settings/integrations/pihole/groups');

        $response->assertOk();
        $response->assertJsonCount(1, 'groups');
        Http::assertSent(fn ($req): bool => str_contains($req->url(), 'db-pihole.example.com'));
    }

    public function test_non_admin_cannot_fetch_pihole_groups(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/admin/settings/integrations/pihole/groups');

        $response->assertForbidden();
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
        $filteredGroupField = collect($serviceData['fields'])->firstWhere('key', 'filtered_group_id');
        $this->assertNotNull($filteredGroupField);
        $this->assertEquals('select-remote', $filteredGroupField['type']);
        $this->assertEquals('/admin/settings/integrations/pihole/groups', $filteredGroupField['remote_url']);
        $this->assertEquals('name', $filteredGroupField['remote_label']);
        $this->assertEquals('id', $filteredGroupField['remote_value']);
    }
}
