<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\IntegrationConfig;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IntegrationControllerEncryptionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function createAdminUser(): User
    {
        $user = User::factory()->create();
        $role = new Role;
        $role->code = 'admin';
        $role->name = 'Admin';
        $role->save();
        $user->roles()->attach($role);

        return $user;
    }

    public function test_stores_password_fields_encrypted_using_config_integrations(): void
    {
        $admin = $this->createAdminUser();

        // Borealis has client_secret with type 'password' in config/integrations.php line 186
        $response = $this->actingAs($admin)->put('/admin/settings/integrations/borealis', [
            'config' => [
                'endpoint' => 'https://auth.example.com',
                'client_id' => 'my-client-id',
                'client_secret' => 'super-secret-value',
                'scope' => 'discord',
            ],
        ]);

        $response->assertRedirect();

        // Verify client_secret is stored encrypted
        $config = IntegrationConfig::where('integration', 'borealis')
            ->where('key', 'client_secret')
            ->first();

        $this->assertNotNull($config);
        $this->assertTrue($config->encrypted, 'client_secret should be marked as encrypted');
        $this->assertEquals('super-secret-value', $config->value, 'Accessor should decrypt the value');

        // Verify raw DB value does not contain plaintext
        $raw = DB::table('integration_configs')
            ->where('integration', 'borealis')
            ->where('key', 'client_secret')
            ->value('value');

        $this->assertStringNotContainsString('super-secret-value', (string) $raw, 'Raw DB value should not contain plaintext');
    }

    public function test_stores_opnsense_key_encrypted(): void
    {
        $admin = $this->createAdminUser();

        // OPNsense has 'key' field with type 'password' in config/integrations.php line 17
        $response = $this->actingAs($admin)->put('/admin/settings/integrations/opnsense', [
            'config' => [
                'endpoint' => 'https://opnsense.local/api',
                'key' => 'my-api-key',
                'secret' => 'my-api-secret',
            ],
        ]);

        $response->assertRedirect();

        // Both 'key' and 'secret' should be encrypted
        $keyConfig = IntegrationConfig::where('integration', 'opnsense')
            ->where('key', 'key')
            ->first();
        $secretConfig = IntegrationConfig::where('integration', 'opnsense')
            ->where('key', 'secret')
            ->first();

        $this->assertNotNull($keyConfig, 'opnsense key config record not found');
        $this->assertNotNull($secretConfig, 'opnsense secret config record not found');
        $this->assertTrue($keyConfig->encrypted);
        $this->assertTrue($secretConfig->encrypted);
        $this->assertEquals('my-api-key', $keyConfig->value);
        $this->assertEquals('my-api-secret', $secretConfig->value);
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

    public function test_stores_bearer_token_encrypted_from_config(): void
    {
        $admin = $this->createAdminUser();

        // Prometheus has bearer_token with type 'password' but it's NOT in ENCRYPTED_KEYS constant
        // This test proves the bug: using ENCRYPTED_KEYS instead of encryptedKeys() method
        $response = $this->actingAs($admin)->put('/admin/settings/integrations/prometheus', [
            'config' => [
                'endpoint' => 'https://prometheus.local',
                'bearer_token' => 'my-bearer-token-secret',
            ],
        ]);

        $response->assertRedirect();

        $config = IntegrationConfig::where('integration', 'prometheus')
            ->where('key', 'bearer_token')
            ->first();

        $this->assertNotNull($config);
        $this->assertTrue($config->encrypted, 'bearer_token should be encrypted (derived from config/integrations.php)');
        $this->assertEquals('my-bearer-token-secret', $config->value);

        // Verify raw DB value does not contain plaintext
        $raw = DB::table('integration_configs')
            ->where('integration', 'prometheus')
            ->where('key', 'bearer_token')
            ->value('value');

        $this->assertStringNotContainsString('my-bearer-token-secret', (string) $raw, 'Raw DB value should not contain plaintext');
    }
}
