<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\SwitchConfig;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SwitchConfigTest extends TestCase
{
    use RefreshDatabase;

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
}
