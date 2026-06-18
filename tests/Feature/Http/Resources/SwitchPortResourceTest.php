<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Resources;

use App\Http\Resources\SwitchPortResource;
use App\Models\SwitchConfig;
use App\Models\SwitchPort;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class SwitchPortResourceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_to_array_returns_expected_keys(): void
    {
        $port = SwitchPort::factory()->create();

        $resource = new SwitchPortResource($port);
        $result = $resource->toArray(Request::create('/'));

        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('interface', $result);
        $this->assertArrayHasKey('description', $result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('admin_status', $result);
        $this->assertArrayHasKey('speed', $result);
        $this->assertArrayHasKey('vlan', $result);
        $this->assertArrayHasKey('poe', $result);
        $this->assertArrayHasKey('duplex', $result);
        $this->assertArrayHasKey('switchport_mode', $result);
    }

    public function test_to_array_returns_correct_values(): void
    {
        $switchConfig = SwitchConfig::factory()->create();
        $port = SwitchPort::factory()->create([
            'switch_config_id' => $switchConfig->id,
            'port_name' => 'GigabitEthernet1/0/1',
            'switch_description' => 'Uplink to core',
            'status' => 'connected',
            'admin_status' => 'up',
            'speed' => '1000',
            'access_vlan' => 100,
            'poe_status' => 'enabled',
            'duplex' => 'full',
            'switchport_mode' => 'access',
        ]);

        $resource = new SwitchPortResource($port);
        $result = $resource->toArray(Request::create('/'));

        $this->assertSame($port->id, $result['id']);
        $this->assertSame('GigabitEthernet1/0/1', $result['interface']);
        $this->assertSame('Uplink to core', $result['description']);
        $this->assertSame('connected', $result['status']);
        $this->assertSame('up', $result['admin_status']);
        $this->assertSame('1000', $result['speed']);
        $this->assertSame(100, $result['vlan']);
        $this->assertSame('enabled', $result['poe']);
        $this->assertSame('full', $result['duplex']);
        $this->assertSame('access', $result['switchport_mode']);
    }

    public function test_to_array_maps_port_name_to_interface(): void
    {
        $port = SwitchPort::factory()->create([
            'port_name' => 'GigabitEthernet1/0/24',
        ]);

        $resource = new SwitchPortResource($port);
        $result = $resource->toArray(Request::create('/'));

        $this->assertSame('GigabitEthernet1/0/24', $result['interface']);
        $this->assertArrayNotHasKey('port_name', $result);
    }

    public function test_to_array_maps_access_vlan_to_vlan(): void
    {
        $port = SwitchPort::factory()->create([
            'access_vlan' => 200,
        ]);

        $resource = new SwitchPortResource($port);
        $result = $resource->toArray(Request::create('/'));

        $this->assertSame(200, $result['vlan']);
        $this->assertArrayNotHasKey('access_vlan', $result);
    }

    public function test_to_array_maps_poe_status_to_poe(): void
    {
        $port = SwitchPort::factory()->create([
            'poe_status' => 'disabled',
        ]);

        $resource = new SwitchPortResource($port);
        $result = $resource->toArray(Request::create('/'));

        $this->assertSame('disabled', $result['poe']);
        $this->assertArrayNotHasKey('poe_status', $result);
    }

    public function test_to_array_handles_null_optional_fields(): void
    {
        $port = SwitchPort::factory()->create([
            'switch_description' => null,
            'poe_status' => null,
            'access_vlan' => null,
        ]);

        $resource = new SwitchPortResource($port);
        $result = $resource->toArray(Request::create('/'));

        $this->assertNull($result['description']);
        $this->assertNull($result['poe']);
        $this->assertNull($result['vlan']);
    }

    public function test_resource_collection_returns_array_of_resources(): void
    {
        $switchConfig = SwitchConfig::factory()->create();
        SwitchPort::factory()->count(3)->create([
            'switch_config_id' => $switchConfig->id,
        ]);

        $collection = SwitchPortResource::collection(SwitchPort::all());
        $resolved = $collection->resolve();

        $this->assertCount(3, $resolved);

        foreach ($resolved as $item) {
            $this->assertArrayHasKey('id', $item);
            $this->assertArrayHasKey('interface', $item);
            $this->assertArrayHasKey('status', $item);
        }
    }
}
