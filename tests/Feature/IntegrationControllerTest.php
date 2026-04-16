<?php

namespace Tests\Feature;

use App\Models\IntegrationConfig;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IntegrationControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
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

    public function test_opnsense_shaper_rules_returns_rules_with_none_option(): void
    {
        Http::fake([
            '*/api/trafficshaper/settings/searchRule' => Http::response([
                'rows' => [
                    ['uuid' => 'abc-123', 'description' => 'Upload Limit', 'sequence' => '1'],
                    ['uuid' => 'def-456', 'description' => 'Download Limit', 'sequence' => '2'],
                ],
                'rowCount' => 2,
                'total' => 2,
                'current' => 1,
            ]),
        ]);

        $admin = $this->createAdminUser();

        IntegrationConfig::setValue('opnsense', 'endpoint', 'https://opnsense.local');
        IntegrationConfig::setValue('opnsense', 'key', 'test-key');
        IntegrationConfig::setValue('opnsense', 'secret', 'test-secret');

        $response = $this->actingAs($admin)->postJson(
            route('admin.settings.integrations.opnsense.shaper-rules')
        );

        $response->assertOk();
        $response->assertJsonCount(3, 'rules');
        $response->assertJsonPath('rules.0.uuid', '');
        $response->assertJsonPath('rules.0.description', 'None (no rate limiting)');
        $response->assertJsonPath('rules.1.uuid', 'abc-123');
        $response->assertJsonPath('rules.1.description', 'Upload Limit (seq: 1)');
        $response->assertJsonPath('rules.2.uuid', 'def-456');
        $response->assertJsonPath('rules.2.description', 'Download Limit (seq: 2)');
    }

    public function test_opnsense_shaper_rules_returns_error_on_auth_failure(): void
    {
        Http::fake([
            '*/api/trafficshaper/settings/searchRule' => Http::response(['message' => 'Unauthorized'], 401),
        ]);

        $admin = $this->createAdminUser();

        IntegrationConfig::setValue('opnsense', 'endpoint', 'https://opnsense.local');
        IntegrationConfig::setValue('opnsense', 'key', 'bad-key');
        IntegrationConfig::setValue('opnsense', 'secret', 'bad-secret');

        $response = $this->actingAs($admin)->postJson(
            route('admin.settings.integrations.opnsense.shaper-rules')
        );

        $response->assertOk();
        $response->assertJsonStructure(['rules', 'error']);
        $response->assertJsonPath('rules', []);
        $this->assertStringContainsString('Failed to fetch shaper rules', $response->json('error'));
    }

    public function test_opnsense_shaper_rules_returns_only_none_when_empty(): void
    {
        Http::fake([
            '*/api/trafficshaper/settings/searchRule' => Http::response([
                'rows' => [],
                'rowCount' => 0,
                'total' => 0,
                'current' => 1,
            ]),
        ]);

        $admin = $this->createAdminUser();

        IntegrationConfig::setValue('opnsense', 'endpoint', 'https://opnsense.local');
        IntegrationConfig::setValue('opnsense', 'key', 'test-key');
        IntegrationConfig::setValue('opnsense', 'secret', 'test-secret');

        $response = $this->actingAs($admin)->postJson(
            route('admin.settings.integrations.opnsense.shaper-rules')
        );

        $response->assertOk();
        $response->assertJsonCount(1, 'rules');
        $response->assertJsonPath('rules.0.uuid', '');
        $response->assertJsonPath('rules.0.description', 'None (no rate limiting)');
    }

    public function test_opnsense_shaper_rules_returns_error_when_endpoint_missing(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->postJson(
            route('admin.settings.integrations.opnsense.shaper-rules')
        );

        $response->assertOk();
        $response->assertJsonPath('error', 'OPNsense endpoint is not configured.');
        $response->assertJsonPath('rules', []);
    }

    public function test_opnsense_shaper_rules_returns_error_when_credentials_missing(): void
    {
        $admin = $this->createAdminUser();

        IntegrationConfig::setValue('opnsense', 'endpoint', 'https://opnsense.local');

        $response = $this->actingAs($admin)->postJson(
            route('admin.settings.integrations.opnsense.shaper-rules')
        );

        $response->assertOk();
        $response->assertJsonPath('error', 'OPNsense API key and secret are required.');
        $response->assertJsonPath('rules', []);
    }

    public function test_opnsense_shaper_rules_merges_request_params_over_db_config(): void
    {
        Http::fake([
            '*/api/trafficshaper/settings/searchRule' => Http::response([
                'rows' => [
                    ['uuid' => 'rule-1', 'description' => 'Rule One', 'sequence' => '10'],
                ],
                'rowCount' => 1,
                'total' => 1,
                'current' => 1,
            ]),
        ]);

        $admin = $this->createAdminUser();

        IntegrationConfig::setValue('opnsense', 'endpoint', 'https://old-endpoint.local');
        IntegrationConfig::setValue('opnsense', 'key', 'old-key');
        IntegrationConfig::setValue('opnsense', 'secret', 'old-secret');

        $response = $this->actingAs($admin)->postJson(
            route('admin.settings.integrations.opnsense.shaper-rules'),
            [
                'endpoint' => 'https://new-endpoint.local',
                'key' => 'new-key',
                'secret' => 'new-secret',
            ]
        );

        $response->assertOk();
        $response->assertJsonCount(2, 'rules');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'new-endpoint.local');
        });
    }

    public function test_opnsense_shaper_rules_requires_authentication(): void
    {
        $response = $this->postJson(
            '/admin/settings/integrations/opnsense/shaper-rules'
        );

        $response->assertUnauthorized();
    }

    public function test_opnsense_shaper_rules_requires_admin_role(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(
            '/admin/settings/integrations/opnsense/shaper-rules'
        );

        $response->assertForbidden();
    }

    public function test_opnsense_shaper_rules_handles_connection_timeout(): void
    {
        Http::fake([
            '*/api/trafficshaper/settings/searchRule' => function () {
                throw new ConnectionException('Connection timed out');
            },
        ]);

        $admin = $this->createAdminUser();

        IntegrationConfig::setValue('opnsense', 'endpoint', 'https://opnsense.local');
        IntegrationConfig::setValue('opnsense', 'key', 'test-key');
        IntegrationConfig::setValue('opnsense', 'secret', 'test-secret');

        $response = $this->actingAs($admin)->postJson(
            route('admin.settings.integrations.opnsense.shaper-rules')
        );

        $response->assertOk();
        $response->assertJsonPath('rules', []);
        $this->assertStringContainsString('Failed to fetch shaper rules', $response->json('error'));
    }

    public function test_opnsense_shaper_rules_handles_rules_with_missing_fields(): void
    {
        Http::fake([
            '*/api/trafficshaper/settings/searchRule' => Http::response([
                'rows' => [
                    ['uuid' => 'uuid-1'],
                    ['uuid' => 'uuid-2', 'description' => 'Has Description'],
                ],
                'rowCount' => 2,
                'total' => 2,
                'current' => 1,
            ]),
        ]);

        $admin = $this->createAdminUser();

        IntegrationConfig::setValue('opnsense', 'endpoint', 'https://opnsense.local');
        IntegrationConfig::setValue('opnsense', 'key', 'test-key');
        IntegrationConfig::setValue('opnsense', 'secret', 'test-secret');

        $response = $this->actingAs($admin)->postJson(
            route('admin.settings.integrations.opnsense.shaper-rules')
        );

        $response->assertOk();
        $response->assertJsonCount(3, 'rules');
        $response->assertJsonPath('rules.1.uuid', 'uuid-1');
        $response->assertJsonPath('rules.1.description', 'Unnamed rule (seq: ?)');
        $response->assertJsonPath('rules.2.uuid', 'uuid-2');
        $response->assertJsonPath('rules.2.description', 'Has Description (seq: ?)');
    }

    public function test_opnsense_config_fields_are_in_correct_order(): void
    {
        /** @var array<string, array{fields: array<string, mixed>}> $integrations */
        $integrations = config('integrations');
        $fields = array_keys($integrations['opnsense']['fields']);

        $expected = [
            'endpoint',
            'key',
            'secret',
            'verify_ssl',
            'captive_portal_id',
            'zone_id',
            'ratelimit_up_uuid',
            'ratelimit_down_uuid',
        ];

        $this->assertSame($expected, $fields);
    }

    public function test_opnsense_ratelimit_fields_are_select_remote_type(): void
    {
        /** @var array<string, array{fields: array<string, array{type: string}>}> $integrations */
        $integrations = config('integrations');
        $fields = $integrations['opnsense']['fields'];

        $this->assertSame('select-remote', $fields['ratelimit_up_uuid']['type']);
        $this->assertSame('select-remote', $fields['ratelimit_down_uuid']['type']);
        $this->assertSame('/admin/settings/integrations/opnsense/shaper-rules', $fields['ratelimit_up_uuid']['remote_url']);
        $this->assertSame('/admin/settings/integrations/opnsense/shaper-rules', $fields['ratelimit_down_uuid']['remote_url']);
        $this->assertSame('description', $fields['ratelimit_up_uuid']['remote_label']);
        $this->assertSame('uuid', $fields['ratelimit_up_uuid']['remote_value']);
    }
}
