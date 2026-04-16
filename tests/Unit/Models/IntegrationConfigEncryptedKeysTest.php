<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\IntegrationConfig;
use Tests\TestCase;

class IntegrationConfigEncryptedKeysTest extends TestCase
{
    public function test_encrypted_keys_constant_exists(): void
    {
        $this->assertTrue(defined(IntegrationConfig::class.'::ENCRYPTED_KEYS'));
    }

    public function test_constant_contains_api_key(): void
    {
        $this->assertContains('api_key', IntegrationConfig::ENCRYPTED_KEYS);
    }

    public function test_constant_contains_password(): void
    {
        $this->assertContains('password', IntegrationConfig::ENCRYPTED_KEYS);
    }

    public function test_constant_contains_secret(): void
    {
        $this->assertContains('secret', IntegrationConfig::ENCRYPTED_KEYS);
    }

    public function test_constant_contains_key(): void
    {
        $this->assertContains('key', IntegrationConfig::ENCRYPTED_KEYS);
    }

    public function test_constant_contains_client_secret(): void
    {
        $this->assertContains('client_secret', IntegrationConfig::ENCRYPTED_KEYS);
    }

    public function test_encrypted_keys_method_exists(): void
    {
        $this->assertTrue(method_exists(IntegrationConfig::class, 'encryptedKeys'));
    }

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

    public function test_encrypted_keys_includes_hardcoded_fallback_keys(): void
    {
        config()->set('integrations', [
            'test_service' => [
                'name' => 'Test',
                'capabilities' => [],
                'fields' => [
                    'custom_secret' => ['type' => 'password', 'label' => 'Custom'],
                ],
            ],
        ]);

        $keys = IntegrationConfig::encryptedKeys();

        // Should include the config-derived key
        $this->assertContains('custom_secret', $keys);

        // Should also include all hardcoded fallback keys
        foreach (IntegrationConfig::ENCRYPTED_KEYS as $fallbackKey) {
            $this->assertContains($fallbackKey, $keys, "Fallback key '{$fallbackKey}' should be present");
        }
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

        // 'password' should appear only once despite being in two integrations and the fallback
        $occurrences = array_count_values($keys);
        $this->assertSame(1, $occurrences['password']);
    }

    public function test_encrypted_keys_returns_list_array(): void
    {
        $keys = IntegrationConfig::encryptedKeys();

        // Should be a sequential list (0-indexed) not an associative array
        $this->assertSame(array_values($keys), $keys);
    }

    public function test_encrypted_keys_with_empty_config_returns_fallback_keys(): void
    {
        config()->set('integrations', []);

        $keys = IntegrationConfig::encryptedKeys();

        $this->assertNotEmpty($keys);
        $this->assertEqualsCanonicalizing(IntegrationConfig::ENCRYPTED_KEYS, $keys);
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

        // None of the non-password types should be derived from config
        // Only hardcoded fallbacks should be present
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

        $keys = IntegrationConfig::encryptedKeys();

        // Should still return fallback keys without error
        $this->assertEqualsCanonicalizing(IntegrationConfig::ENCRYPTED_KEYS, $keys);
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
        // Use the real config to verify all password fields are covered
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
            $this->assertContains($key, $encryptedKeys, "Password field '{$key}' from config should be in encryptedKeys()");
        }
    }
}
