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
            '*/api/trafficshaper/settings/search_rule' => Http::response([
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
            '*/api/trafficshaper/settings/search_rule' => Http::response(['message' => 'Unauthorized'], 401),
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
            '*/api/trafficshaper/settings/search_rule' => Http::response([
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
            '*/api/trafficshaper/settings/search_rule' => Http::response([
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
            '*/api/trafficshaper/settings/search_rule' => function () {
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
            '*/api/trafficshaper/settings/search_rule' => Http::response([
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
            'dhcp_server',
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

    public function test_opnsense_zone_fields_are_select_remote_type(): void
    {
        /** @var array<string, array{fields: array<string, array{type: string, remote_url?: string, remote_label?: string, remote_value?: string}>}> $integrations */
        $integrations = config('integrations');
        $fields = $integrations['opnsense']['fields'];

        $this->assertSame('select-remote', $fields['captive_portal_id']['type']);
        $this->assertSame('select-remote', $fields['zone_id']['type']);
        $this->assertSame('/admin/settings/integrations/opnsense/zones', $fields['captive_portal_id']['remote_url']);
        $this->assertSame('/admin/settings/integrations/opnsense/zones', $fields['zone_id']['remote_url']);
        $this->assertSame('name', $fields['captive_portal_id']['remote_label']);
        $this->assertSame('id', $fields['captive_portal_id']['remote_value']);
        $this->assertSame('name', $fields['zone_id']['remote_label']);
        $this->assertSame('id', $fields['zone_id']['remote_value']);
    }

    public function test_opnsense_zones_returns_parsed_zones(): void
    {
        Http::fake([
            '*/api/captiveportal/settings/get' => Http::response([
                'zone' => [
                    'zones' => [
                        'zone' => [
                            'abc-uuid-1' => ['zoneid' => '0', 'description' => 'Default Zone'],
                            'def-uuid-2' => ['zoneid' => '1', 'description' => 'Guest Zone'],
                        ],
                    ],
                ],
            ]),
        ]);

        $admin = $this->createAdminUser();

        IntegrationConfig::setValue('opnsense', 'endpoint', 'https://opnsense.local');
        IntegrationConfig::setValue('opnsense', 'key', 'test-key');
        IntegrationConfig::setValue('opnsense', 'secret', 'test-secret');

        $response = $this->actingAs($admin)->postJson(
            route('admin.settings.integrations.opnsense.zones')
        );

        $response->assertOk();
        $response->assertJsonCount(2, 'zones');
        $response->assertJsonPath('zones.0.id', '0');
        $response->assertJsonPath('zones.0.name', 'Default Zone (ID: 0)');
        $response->assertJsonPath('zones.1.id', '1');
        $response->assertJsonPath('zones.1.name', 'Guest Zone (ID: 1)');
    }

    public function test_opnsense_zones_returns_error_on_auth_failure(): void
    {
        Http::fake([
            '*/api/captiveportal/settings/get' => Http::response(['message' => 'Unauthorized'], 401),
        ]);

        $admin = $this->createAdminUser();

        IntegrationConfig::setValue('opnsense', 'endpoint', 'https://opnsense.local');
        IntegrationConfig::setValue('opnsense', 'key', 'bad-key');
        IntegrationConfig::setValue('opnsense', 'secret', 'bad-secret');

        $response = $this->actingAs($admin)->postJson(
            route('admin.settings.integrations.opnsense.zones')
        );

        $response->assertOk();
        $response->assertJsonStructure(['zones', 'error']);
        $response->assertJsonPath('zones', []);
        $this->assertStringContainsString('Failed to fetch zones', $response->json('error'));
    }

    public function test_opnsense_zones_returns_empty_array_when_no_zones(): void
    {
        Http::fake([
            '*/api/captiveportal/settings/get' => Http::response([
                'zone' => [
                    'zones' => [
                        'zone' => [],
                    ],
                ],
            ]),
        ]);

        $admin = $this->createAdminUser();

        IntegrationConfig::setValue('opnsense', 'endpoint', 'https://opnsense.local');
        IntegrationConfig::setValue('opnsense', 'key', 'test-key');
        IntegrationConfig::setValue('opnsense', 'secret', 'test-secret');

        $response = $this->actingAs($admin)->postJson(
            route('admin.settings.integrations.opnsense.zones')
        );

        $response->assertOk();
        $response->assertJsonCount(0, 'zones');
    }

    public function test_opnsense_zones_returns_error_when_endpoint_missing(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->postJson(
            route('admin.settings.integrations.opnsense.zones')
        );

        $response->assertOk();
        $response->assertJsonPath('error', 'OPNsense endpoint is not configured.');
        $response->assertJsonPath('zones', []);
    }

    public function test_opnsense_zones_returns_error_when_credentials_missing(): void
    {
        $admin = $this->createAdminUser();

        IntegrationConfig::setValue('opnsense', 'endpoint', 'https://opnsense.local');

        $response = $this->actingAs($admin)->postJson(
            route('admin.settings.integrations.opnsense.zones')
        );

        $response->assertOk();
        $response->assertJsonPath('error', 'OPNsense API key and secret are required.');
        $response->assertJsonPath('zones', []);
    }

    public function test_opnsense_zones_merges_request_params_over_db_config(): void
    {
        Http::fake([
            '*/api/captiveportal/settings/get' => Http::response([
                'zone' => [
                    'zones' => [
                        'zone' => [
                            'abc-uuid-1' => ['zoneid' => '0', 'description' => 'Default Zone'],
                        ],
                    ],
                ],
            ]),
        ]);

        $admin = $this->createAdminUser();

        IntegrationConfig::setValue('opnsense', 'endpoint', 'https://old-endpoint.local');
        IntegrationConfig::setValue('opnsense', 'key', 'old-key');
        IntegrationConfig::setValue('opnsense', 'secret', 'old-secret');

        $response = $this->actingAs($admin)->postJson(
            route('admin.settings.integrations.opnsense.zones'),
            [
                'endpoint' => 'https://new-endpoint.local',
                'key' => 'new-key',
                'secret' => 'new-secret',
            ]
        );

        $response->assertOk();
        $response->assertJsonCount(1, 'zones');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'new-endpoint.local');
        });
    }

    public function test_opnsense_zones_requires_authentication(): void
    {
        $response = $this->postJson(
            '/admin/settings/integrations/opnsense/zones'
        );

        $response->assertUnauthorized();
    }

    public function test_opnsense_zones_requires_admin_role(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(
            '/admin/settings/integrations/opnsense/zones'
        );

        $response->assertForbidden();
    }

    public function test_opnsense_zones_handles_connection_timeout(): void
    {
        Http::fake([
            '*/api/captiveportal/settings/get' => function () {
                throw new ConnectionException('Connection timed out');
            },
        ]);

        $admin = $this->createAdminUser();

        IntegrationConfig::setValue('opnsense', 'endpoint', 'https://opnsense.local');
        IntegrationConfig::setValue('opnsense', 'key', 'test-key');
        IntegrationConfig::setValue('opnsense', 'secret', 'test-secret');

        $response = $this->actingAs($admin)->postJson(
            route('admin.settings.integrations.opnsense.zones')
        );

        $response->assertOk();
        $response->assertJsonPath('zones', []);
        $this->assertStringContainsString('Failed to fetch zones', $response->json('error'));
    }

    public function test_opnsense_zones_handles_zones_with_missing_fields(): void
    {
        Http::fake([
            '*/api/captiveportal/settings/get' => Http::response([
                'zone' => [
                    'zones' => [
                        'zone' => [
                            'uuid-1' => ['zoneid' => '5'],
                            'uuid-2' => ['zoneid' => '3', 'description' => 'Has Description'],
                        ],
                    ],
                ],
            ]),
        ]);

        $admin = $this->createAdminUser();

        IntegrationConfig::setValue('opnsense', 'endpoint', 'https://opnsense.local');
        IntegrationConfig::setValue('opnsense', 'key', 'test-key');
        IntegrationConfig::setValue('opnsense', 'secret', 'test-secret');

        $response = $this->actingAs($admin)->postJson(
            route('admin.settings.integrations.opnsense.zones')
        );

        $response->assertOk();
        $response->assertJsonCount(2, 'zones');
        // Sorted by zone ID: 3 before 5
        $response->assertJsonPath('zones.0.id', '3');
        $response->assertJsonPath('zones.0.name', 'Has Description (ID: 3)');
        $response->assertJsonPath('zones.1.id', '5');
        $response->assertJsonPath('zones.1.name', 'Zone 5 (ID: 5)');
    }

    public function test_opnsense_zones_sorts_by_zone_id(): void
    {
        Http::fake([
            '*/api/captiveportal/settings/get' => Http::response([
                'zone' => [
                    'zones' => [
                        'zone' => [
                            'uuid-a' => ['zoneid' => '10', 'description' => 'Zone Ten'],
                            'uuid-b' => ['zoneid' => '2', 'description' => 'Zone Two'],
                            'uuid-c' => ['zoneid' => '0', 'description' => 'Zone Zero'],
                        ],
                    ],
                ],
            ]),
        ]);

        $admin = $this->createAdminUser();

        IntegrationConfig::setValue('opnsense', 'endpoint', 'https://opnsense.local');
        IntegrationConfig::setValue('opnsense', 'key', 'test-key');
        IntegrationConfig::setValue('opnsense', 'secret', 'test-secret');

        $response = $this->actingAs($admin)->postJson(
            route('admin.settings.integrations.opnsense.zones')
        );

        $response->assertOk();
        $response->assertJsonCount(3, 'zones');
        $response->assertJsonPath('zones.0.id', '0');
        $response->assertJsonPath('zones.1.id', '2');
        $response->assertJsonPath('zones.2.id', '10');
    }
}
