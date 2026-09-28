<?php

namespace Tests\Feature\Admin;

use App\Enums\Capability;
use App\Models\CapabilityAssignment;
use App\Models\ConnectionTestLog;
use App\Models\IntegrationConfig;
use App\Models\Role;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
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
        CapabilityAssignment::assign(Capability::Dhcp, 'opnsense');
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
        CapabilityAssignment::assign(Capability::Dhcp, 'opnsense');

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

    #[DataProvider('numericConfigValuesProvider')]
    public function test_numeric_config_values_are_stored_with_correct_type(string $integration, array $config, string $field, int $expected): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/settings/integrations/'.$integration, [
            'config' => $config,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $stored = IntegrationConfig::getValue($integration, $field);
        $this->assertSame($expected, $stored);
        $this->assertIsInt($stored);
    }

    public static function numericConfigValuesProvider(): array
    {
        return [
            'pihole filtered_group_id' => [
                'pihole',
                ['endpoint' => 'https://pihole.test', 'password' => 'secret', 'filtered_group_id' => '3', 'enabled' => '1', 'verify_ssl' => '1'],
                'filtered_group_id',
                3,
            ],
            'prometheus default_step' => [
                'prometheus',
                ['endpoint' => 'https://prometheus.test', 'bearer_token' => 'tok', 'verify_ssl' => '1', 'default_step' => '60', 'enabled' => '1'],
                'default_step',
                60,
            ],
        ];
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

    #[DataProvider('piholeGroupsErrorProvider')]
    public function test_pihole_groups_returns_error(?Closure $fakeSetup, array $payload, string $expectedErrorSubstring): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        if ($fakeSetup instanceof Closure) {
            $fakeSetup();
        }

        $response = $this->actingAs($admin)->postJson('/admin/settings/integrations/pihole/groups', $payload);

        $response->assertOk();
        $response->assertJsonPath('groups', []);
        $this->assertStringContainsString($expectedErrorSubstring, $response->json('error'));
    }

    public static function piholeGroupsErrorProvider(): array
    {
        return [
            'auth failure' => [
                fn () => Http::fake(['*/api/auth' => Http::response(['error' => ['key' => 'unauthorized']], 401)]),
                ['endpoint' => 'https://pihole.test', 'password' => 'wrong-password'],
                'authentication failed',
            ],
            'groups failure' => [
                fn () => Http::fake([
                    '*/api/auth' => Http::response(['session' => ['sid' => 'tok', 'validity' => 300]], 200),
                    '*/api/groups' => Http::response('Forbidden', 403),
                ]),
                ['endpoint' => 'https://pihole.test', 'password' => 'test'],
                'Failed to fetch Pi-hole groups',
            ],
            'no endpoint configured' => [
                null,
                ['password' => 'test'],
                'endpoint is not configured',
            ],
            'no password configured' => [
                null,
                ['endpoint' => 'https://pihole.test'],
                'password is not configured',
            ],
            'sid missing from auth response' => [
                fn () => Http::fake(['*/api/auth' => Http::response(['session' => ['validity' => 300]], 200)]),
                ['endpoint' => 'https://pihole.test', 'password' => 'test'],
                'no session ID',
            ],
        ];
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

    // -------------------------------------------------------------------------
    // Encrypted config fields (merged from IntegrationControllerEncryptionTest)
    // -------------------------------------------------------------------------

    #[DataProvider('encryptedConfigFieldsProvider')]
    public function test_encrypted_config_fields_are_stored_encrypted(string $integration, array $config, string $field, string $expectedValue): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/settings/integrations/'.$integration, [
            'config' => $config,
        ]);

        $response->assertRedirect();

        $configRecord = IntegrationConfig::where('integration', $integration)
            ->where('key', $field)
            ->first();

        $this->assertNotNull($configRecord);
        $this->assertTrue($configRecord->encrypted, $field.' should be marked as encrypted');
        $this->assertEquals($expectedValue, $configRecord->value, 'Accessor should decrypt the value');

        $raw = DB::table('integration_configs')
            ->where('integration', $integration)
            ->where('key', $field)
            ->value('value');

        $this->assertStringNotContainsString($expectedValue, (string) $raw, 'Raw DB value should not contain plaintext');
    }

    public static function encryptedConfigFieldsProvider(): array
    {
        return [
            // Borealis has client_secret with type 'password' in config/integrations.php.
            'borealis client_secret' => [
                'borealis',
                ['endpoint' => 'https://auth.example.com', 'client_id' => 'my-client-id', 'client_secret' => 'super-secret-value', 'scope' => 'discord'],
                'client_secret',
                'super-secret-value',
            ],
            // OPNsense has 'key' and 'secret' fields with type 'password'.
            'opnsense key' => [
                'opnsense',
                ['endpoint' => 'https://opnsense.local/api', 'key' => 'my-api-key', 'secret' => 'my-api-secret'],
                'key',
                'my-api-key',
            ],
            'opnsense secret' => [
                'opnsense',
                ['endpoint' => 'https://opnsense.local/api', 'key' => 'my-api-key', 'secret' => 'my-api-secret'],
                'secret',
                'my-api-secret',
            ],
            // Prometheus has bearer_token with type 'password' but it's NOT in the ENCRYPTED_KEYS
            // constant — this proves encryption is derived from config/integrations.php, not the constant.
            'prometheus bearer_token' => [
                'prometheus',
                ['endpoint' => 'https://prometheus.local', 'bearer_token' => 'my-bearer-token-secret'],
                'bearer_token',
                'my-bearer-token-secret',
            ],
            'kea password_v4' => [
                'kea',
                ['endpoint_v4' => 'https://kea.local:8000', 'username_v4' => 'admin', 'password_v4' => 'super-secret-kea-password'],
                'password_v4',
                'super-secret-kea-password',
            ],
            'kea password_v6' => [
                'kea',
                ['endpoint_v6' => 'https://kea.local:8000', 'username_v6' => 'admin', 'password_v6' => 'super-secret-kea-password'],
                'password_v6',
                'super-secret-kea-password',
            ],
        ];
    }

    public function test_stores_non_password_fields_unencrypted(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/settings/integrations/opnsense', [
            'config' => [
                'endpoint' => 'https://opnsense.local/api',
                'verify_ssl' => '1',
            ],
        ]);

        $response->assertRedirect();

        $config = IntegrationConfig::where('integration', 'opnsense')
            ->where('key', 'verify_ssl')
            ->first();

        $this->assertNotNull($config);
        $this->assertFalse($config->encrypted, 'verify_ssl should NOT be encrypted');
    }

    #[DataProvider('keaValidationFailureProvider')]
    public function test_kea_config_validation_failures(array $config, array $expectedErrorKeys): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/settings/integrations/kea', [
            'config' => $config,
        ]);

        $response->assertSessionHasErrors($expectedErrorKeys);
    }

    public static function keaValidationFailureProvider(): array
    {
        return [
            'username_v4 without password_v4' => [
                ['endpoint_v4' => 'https://kea.local:8000', 'username_v4' => 'admin'],
                ['config.password_v4'],
            ],
            'password_v4 without username_v4' => [
                ['endpoint_v4' => 'https://kea.local:8000', 'password_v4' => 'super-secret-kea-password'],
                ['config.username_v4'],
            ],
            'username_v6 without password_v6' => [
                ['endpoint_v4' => 'https://kea.local:8000', 'username_v6' => 'admin'],
                ['config.password_v6'],
            ],
            'password_v6 without username_v6' => [
                ['endpoint_v4' => 'https://kea.local:8000', 'password_v6' => 'super-secret-kea-password'],
                ['config.username_v6'],
            ],
            'no endpoints provided' => [
                ['endpoint_v4' => '', 'endpoint_v6' => ''],
                ['config.endpoint_v4', 'config.endpoint_v6'],
            ],
        ];
    }

    #[DataProvider('keaValidConfigProvider')]
    public function test_kea_config_passes_validation(array $config): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/settings/integrations/kea', [
            'config' => $config,
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();
    }

    public static function keaValidConfigProvider(): array
    {
        return [
            'ipv4 only' => [
                ['endpoint_v4' => 'https://kea.local:8000'],
            ],
            'ipv6 only' => [
                ['endpoint_v6' => 'https://kea.local:8000'],
            ],
            'both endpoints' => [
                ['endpoint_v4' => 'https://kea.local:8000', 'endpoint_v6' => 'https://kea.local:8001'],
            ],
        ];
    }
}
