<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\IntegrationConfig;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class IntegrationConfigEncryptedKeysTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_encrypted_keys_derives_password_fields_from_config(): void
    {
        config()->set('integrations', [
            'test_service' => [
                'name' => 'Test',
                'capabilities' => [],
                'fields' => [
                    'api_token' => ['type' => 'password', 'label' => 'Token'],
                    'endpoint' => ['type' => 'url', 'label' => 'Endpoint'],
                    'secret_key' => ['type' => 'password', 'label' => 'Secret'],
                ],
            ],
        ]);

        $keys = IntegrationConfig::encryptedKeys();

        $this->assertContains('api_token', $keys);
        $this->assertContains('secret_key', $keys);
        $this->assertNotContains('endpoint', $keys);
    }

    public function test_encrypted_keys_returns_unique_values(): void
    {
        config()->set('integrations', [
            'service_a' => [
                'name' => 'A',
                'capabilities' => [],
                'fields' => [
                    'password' => ['type' => 'password', 'label' => 'Password'],
                ],
            ],
            'service_b' => [
                'name' => 'B',
                'capabilities' => [],
                'fields' => [
                    'password' => ['type' => 'password', 'label' => 'Password'],
                ],
            ],
        ]);

        $keys = IntegrationConfig::encryptedKeys();

        $occurrences = array_count_values($keys);
        $this->assertSame(1, $occurrences['password']);
    }

    public function test_encrypted_keys_returns_list_array(): void
    {
        $keys = IntegrationConfig::encryptedKeys();

        $this->assertSame(array_values($keys), $keys);
    }

    public function test_encrypted_keys_with_empty_config_returns_no_keys(): void
    {
        config()->set('integrations', []);

        $this->assertSame([], IntegrationConfig::encryptedKeys());
    }

    public function test_encrypted_keys_ignores_non_password_field_types(): void
    {
        config()->set('integrations', [
            'test_service' => [
                'name' => 'Test',
                'capabilities' => [],
                'fields' => [
                    'endpoint' => ['type' => 'url', 'label' => 'Endpoint'],
                    'name' => ['type' => 'text', 'label' => 'Name'],
                    'enabled' => ['type' => 'toggle', 'label' => 'Enabled'],
                    'zone' => ['type' => 'select', 'label' => 'Zone'],
                    'remote' => ['type' => 'select-remote', 'label' => 'Remote'],
                ],
            ],
        ]);

        $keys = IntegrationConfig::encryptedKeys();

        $this->assertNotContains('endpoint', $keys);
        $this->assertNotContains('name', $keys);
        $this->assertNotContains('enabled', $keys);
        $this->assertNotContains('zone', $keys);
        $this->assertNotContains('remote', $keys);
    }

    public function test_encrypted_keys_handles_missing_fields_key_in_integration(): void
    {
        config()->set('integrations', [
            'no_fields' => [
                'name' => 'No Fields',
                'capabilities' => [],
            ],
        ]);

        $this->assertSame([], IntegrationConfig::encryptedKeys());
    }

    public function test_encrypted_keys_handles_missing_type_in_field(): void
    {
        config()->set('integrations', [
            'test' => [
                'name' => 'Test',
                'capabilities' => [],
                'fields' => [
                    'broken_field' => ['label' => 'No Type'],
                ],
            ],
        ]);

        $keys = IntegrationConfig::encryptedKeys();

        $this->assertNotContains('broken_field', $keys);
    }

    public function test_encrypted_keys_covers_all_real_config_password_fields(): void
    {
        /** @var array<string, array{fields?: array<string, array{type: string}>}> $integrations */
        $integrations = config('integrations', []);

        $passwordFieldKeys = [];
        foreach ($integrations as $integration) {
            foreach ($integration['fields'] ?? [] as $key => $field) {
                if (($field['type'] ?? '') === 'password') {
                    $passwordFieldKeys[] = $key;
                }
            }
        }

        $passwordFieldKeys = array_values(array_unique($passwordFieldKeys));

        $encryptedKeys = IntegrationConfig::encryptedKeys();

        foreach ($passwordFieldKeys as $key) {
            $this->assertContains($key, $encryptedKeys, sprintf("Password field '%s' from config should be in encryptedKeys()", $key));
        }
    }

    public function test_stored_encrypted_value_decrypts_without_a_declared_key(): void
    {
        config()->set('integrations', []);

        $row = new IntegrationConfig(['integration' => 'legacy', 'key' => 'api_token', 'encrypted' => true]);
        $row->value = 'legacy-secret';
        $row->save();

        $this->assertNotSame('legacy-secret', json_decode((string) $row->getRawOriginal('value'), true)['v']);
        $this->assertSame('legacy-secret', IntegrationConfig::getValue('legacy', 'api_token'));
    }
}
