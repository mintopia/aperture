<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\IntegrationConfig;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IntegrationConfigTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_can_create_with_plain_value(): void
    {
        $config = new IntegrationConfig;
        $config->integration = 'opnsense';
        $config->key = 'api_host';
        $config->encrypted = false;
        $config->value = 'https://firewall.local';
        $config->save();

        $config->refresh();

        $this->assertEquals('https://firewall.local', $config->value);
    }

    public function test_can_create_with_encrypted_value(): void
    {
        $config = new IntegrationConfig;
        $config->integration = 'opnsense';
        $config->key = 'api_secret';
        $config->encrypted = true;
        $config->value = 'my-secret-key';
        $config->save();

        $config->refresh();

        $this->assertEquals('my-secret-key', $config->value);

        // Raw DB value should NOT contain the plain text
        $raw = DB::table('integration_configs')->where('id', $config->id)->value('value');
        $this->assertNotEquals('my-secret-key', $raw);
        $this->assertStringNotContainsString('my-secret-key', (string) $raw);
    }

    public function test_get_value_returns_existing_config(): void
    {
        $config = new IntegrationConfig;
        $config->integration = 'librenms';
        $config->key = 'url';
        $config->encrypted = false;
        $config->value = 'https://librenms.local';
        $config->save();

        $result = IntegrationConfig::getValue('librenms', 'url');

        $this->assertEquals('https://librenms.local', $result);
    }

    public function test_get_value_returns_default_for_missing(): void
    {
        $result = IntegrationConfig::getValue('nonexistent', 'missing', 'default_value');

        $this->assertEquals('default_value', $result);
    }

    public function test_set_value_creates_new_config(): void
    {
        IntegrationConfig::setValue('pihole', 'api_token', 'abc123');

        $this->assertDatabaseHas('integration_configs', [
            'integration' => 'pihole',
            'key' => 'api_token',
        ]);

        $this->assertEquals('abc123', IntegrationConfig::getValue('pihole', 'api_token'));
    }

    public function test_set_value_updates_existing_config(): void
    {
        IntegrationConfig::setValue('pihole', 'api_token', 'first_value');
        IntegrationConfig::setValue('pihole', 'api_token', 'second_value');

        $this->assertEquals('second_value', IntegrationConfig::getValue('pihole', 'api_token'));

        $count = IntegrationConfig::where('integration', 'pihole')
            ->where('key', 'api_token')
            ->count();
        $this->assertEquals(1, $count);
    }

    public function test_get_all_returns_all_keys(): void
    {
        IntegrationConfig::setValue('opnsense', 'host', 'https://fw.local');
        IntegrationConfig::setValue('opnsense', 'port', '443');

        $all = IntegrationConfig::getAll('opnsense');

        $this->assertArrayHasKey('host', $all);
        $this->assertArrayHasKey('port', $all);
        $this->assertEquals('https://fw.local', $all['host']);
        $this->assertEquals('443', $all['port']);
    }

    public function test_encrypted_value_stored_encrypted_in_db(): void
    {
        IntegrationConfig::setValue('opnsense', 'api_secret', 'super-secret', true);

        $raw = DB::table('integration_configs')
            ->where('integration', 'opnsense')
            ->where('key', 'api_secret')
            ->value('value');

        $this->assertStringNotContainsString('super-secret', (string) $raw);

        // But the accessor should decrypt it
        $this->assertEquals('super-secret', IntegrationConfig::getValue('opnsense', 'api_secret'));
    }

    public function test_null_value_handled(): void
    {
        $config = new IntegrationConfig;
        $config->integration = 'test';
        $config->key = 'nullable';
        $config->encrypted = false;
        $config->value = null;
        $config->save();

        $config->refresh();

        $this->assertNull($config->value);
    }

    public function test_factory_creates_valid_instance(): void
    {
        $config = IntegrationConfig::factory()->create();

        $this->assertInstanceOf(IntegrationConfig::class, $config);
        $this->assertNotNull($config->integration);
        $this->assertNotNull($config->key);
        $this->assertNotNull($config->id);
    }
}
