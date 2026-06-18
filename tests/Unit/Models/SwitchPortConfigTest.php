<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\SwitchPort;
use App\Models\SwitchPortConfig;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SwitchPortConfigTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_factory_creates_valid_instance(): void
    {
        $config = SwitchPortConfig::factory()->create();

        $this->assertInstanceOf(SwitchPortConfig::class, $config);
        $this->assertNotNull($config->id);
        $this->assertNotNull($config->config_text);
        $this->assertNotNull($config->config_hash);
    }

    public function test_fillable_fields(): void
    {
        $port = SwitchPort::factory()->create();
        $configText = "interface GigabitEthernet1/0/1\n switchport access vlan 200\n switchport mode access";
        $configHash = md5($configText);

        $config = SwitchPortConfig::factory()->create([
            'switch_port_id' => $port->id,
            'config_text' => $configText,
            'config_hash' => $configHash,
            'last_fetched_at' => now(),
        ]);

        $this->assertDatabaseHas('switch_port_configs', [
            'id' => $config->id,
            'switch_port_id' => $port->id,
            'config_text' => $configText,
            'config_hash' => $configHash,
        ]);
    }

    public function test_last_fetched_at_cast_to_datetime(): void
    {
        $config = SwitchPortConfig::factory()->create([
            'last_fetched_at' => '2025-06-15 12:00:00',
        ]);

        $config->refresh();

        $this->assertInstanceOf(Carbon::class, $config->last_fetched_at);
    }

    public function test_belongs_to_switch_port(): void
    {
        $port = SwitchPort::factory()->create();
        $config = SwitchPortConfig::factory()->create([
            'switch_port_id' => $port->id,
        ]);

        $this->assertInstanceOf(SwitchPort::class, $config->switchPort);
        $this->assertTrue($config->switchPort->is($port));
    }

    public function test_cascading_delete_when_switch_port_deleted(): void
    {
        $port = SwitchPort::factory()->create();
        $config = SwitchPortConfig::factory()->create([
            'switch_port_id' => $port->id,
        ]);

        $port->delete();

        $this->assertDatabaseMissing('switch_port_configs', ['id' => $config->id]);
    }

    public function test_config_text_stores_multiline_content(): void
    {
        $configText = implode("\n", [
            'interface GigabitEthernet1/0/24',
            ' description Server Port',
            ' switchport access vlan 100',
            ' switchport mode access',
            ' spanning-tree portfast',
            ' no shutdown',
        ]);

        $config = SwitchPortConfig::factory()->create([
            'config_text' => $configText,
            'config_hash' => md5($configText),
        ]);

        $config->refresh();

        $this->assertSame($configText, $config->config_text);
    }

    public function test_config_hash_matches_content(): void
    {
        $configText = "interface GigabitEthernet1/0/1\n switchport mode trunk";
        $expectedHash = md5($configText);

        $config = SwitchPortConfig::factory()->create([
            'config_text' => $configText,
            'config_hash' => $expectedHash,
        ]);

        $config->refresh();

        $this->assertSame($expectedHash, $config->config_hash);
    }
}
