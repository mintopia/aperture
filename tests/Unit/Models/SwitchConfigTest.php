<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\SwitchConfig;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SwitchConfigTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_can_create_switch_config(): void
    {
        $switch = SwitchConfig::factory()->create([
            'name' => 'Core Switch',
            'hostname' => 'core-switch.example.com',
            'type' => 'cisco',
            'username' => 'admin',
            'password' => 'password123',
            'enable_password' => 'enable123',
            'enabled' => true,
            'port' => 22,
            'timeout' => 5,
        ]);

        $this->assertDatabaseHas('switch_configs', [
            'id' => $switch->id,
            'name' => 'Core Switch',
            'hostname' => 'core-switch.example.com',
            'type' => 'cisco',
            'username' => 'admin',
            'enabled' => true,
            'port' => 22,
            'timeout' => 5,
        ]);
    }

    public function test_password_is_encrypted_in_database(): void
    {
        $switch = SwitchConfig::factory()->create([
            'password' => 'password123',
        ]);

        $raw = DB::table('switch_configs')->where('id', $switch->id)->value('password');

        $this->assertNotEquals('password123', $raw);
        $this->assertStringNotContainsString('password123', (string) $raw);
    }

    public function test_enable_password_is_encrypted_in_database(): void
    {
        $switch = SwitchConfig::factory()->create([
            'enable_password' => 'enable123',
        ]);

        $raw = DB::table('switch_configs')->where('id', $switch->id)->value('enable_password');

        $this->assertNotEquals('enable123', $raw);
        $this->assertStringNotContainsString('enable123', (string) $raw);
    }

    public function test_password_is_decrypted_when_accessed(): void
    {
        $switch = SwitchConfig::factory()->create([
            'password' => 'password123',
        ]);

        $switch->refresh();

        $this->assertSame('password123', $switch->password);
    }

    public function test_hostname_unique_constraint(): void
    {
        SwitchConfig::factory()->create([
            'hostname' => 'duplicate.example.com',
        ]);

        $this->expectException(QueryException::class);

        SwitchConfig::factory()->create([
            'hostname' => 'duplicate.example.com',
        ]);
    }

    public function test_factory_creates_valid_instance(): void
    {
        $switch = SwitchConfig::factory()->create();

        $this->assertInstanceOf(SwitchConfig::class, $switch);
    }

    public function test_enabled_cast_as_boolean(): void
    {
        $switch = SwitchConfig::factory()->create(['enabled' => true]);

        $this->assertIsBool($switch->enabled);
    }

    public function test_password_and_enable_password_hidden_from_serialization(): void
    {
        $switch = SwitchConfig::factory()->create();

        $array = $switch->toArray();

        $this->assertArrayNotHasKey('password', $array);
        $this->assertArrayNotHasKey('enable_password', $array);
    }

    public function test_default_fallback_returns_switch_config_instance(): void
    {
        $switch = SwitchConfig::defaultFallback();

        $this->assertInstanceOf(SwitchConfig::class, $switch);
    }

    public function test_default_fallback_uses_config_values(): void
    {
        config([
            'aperture.cisco.hostname' => 'switch.example.com',
            'aperture.cisco.username' => 'admin',
            'aperture.cisco.password' => 'secret',
            'aperture.cisco.enablePassword' => 'enable',
            'aperture.cisco.timeout' => 10,
        ]);

        $switch = SwitchConfig::defaultFallback();

        $this->assertSame('Default Cisco Switch', $switch->name);
        $this->assertSame('switch.example.com', $switch->hostname);
        $this->assertSame('cisco', $switch->type);
        $this->assertSame('admin', $switch->username);
        $this->assertSame('secret', $switch->password);
        $this->assertSame('enable', $switch->enable_password);
        $this->assertTrue($switch->enabled);
        $this->assertSame(22, $switch->port);
        $this->assertSame(10, $switch->timeout);
    }

    public function test_default_fallback_uses_empty_string_defaults_when_config_absent(): void
    {
        config([
            'aperture.cisco.hostname' => null,
            'aperture.cisco.username' => null,
            'aperture.cisco.password' => null,
            'aperture.cisco.enablePassword' => null,
        ]);

        $switch = SwitchConfig::defaultFallback();

        $this->assertSame('', $switch->hostname);
        $this->assertSame('', $switch->username);
        $this->assertSame('', $switch->password);
        $this->assertSame('', $switch->enable_password);
    }

    public function test_default_fallback_timeout_defaults_to_5_when_not_configured(): void
    {
        $config = config()->all();
        if (isset($config['aperture']['cisco'])) {
            unset($config['aperture']['cisco']['timeout']);
            config($config);
        }

        $switch = SwitchConfig::defaultFallback();

        $this->assertSame(5, $switch->timeout);
    }

    public function test_default_fallback_does_not_persist_to_database(): void
    {
        $switch = SwitchConfig::defaultFallback();

        $this->assertFalse($switch->exists);
        $this->assertDatabaseCount('switch_configs', 0);
    }

    public function test_private_key_and_passphrase_are_encrypted_at_rest_and_hidden(): void
    {
        $switch = SwitchConfig::factory()->withPrivateKey('-----BEGIN RSA PRIVATE KEY-----\nsecret\n-----END RSA PRIVATE KEY-----')->create(['passphrase' => 'pp-secret']);

        $raw = DB::table('switch_configs')->where('id', $switch->id)->first();
        $this->assertStringNotContainsString('secret', (string) $raw->private_key);
        $this->assertStringNotContainsString('pp-secret', (string) $raw->passphrase);
        $this->assertTrue($switch->refresh()->usesPrivateKey());
        $this->assertSame('pp-secret', $switch->passphrase);
        $this->assertArrayNotHasKey('private_key', $switch->toArray());
        $this->assertArrayNotHasKey('passphrase', $switch->toArray());
        $this->assertTrue($switch->toPublicArray()['has_private_key']);
        $this->assertArrayNotHasKey('private_key', $switch->toPublicArray());
    }

    public function test_host_key_fingerprint(): void
    {
        $blob = 'blob-bytes';
        $switch = SwitchConfig::factory()->make(['host_key' => 'ssh-ed25519 '.base64_encode($blob)]);

        $this->assertSame('SHA256:'.rtrim(base64_encode(hash('sha256', $blob, true)), '='), $switch->hostKeyFingerprint());
        $this->assertNull(SwitchConfig::factory()->make(['host_key' => null])->hostKeyFingerprint());
        $this->assertNull(SwitchConfig::factory()->make(['host_key' => 'ssh-ed25519 !!notbase64'])->hostKeyFingerprint());
    }

    public function test_uses_private_key_false_without_key(): void
    {
        $this->assertFalse(SwitchConfig::factory()->make()->usesPrivateKey());
    }
}
