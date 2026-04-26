<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Resources;

use App\Http\Resources\SwitchConfigResource;
use App\Models\SwitchConfig;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class SwitchConfigResourceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_to_array_returns_expected_keys(): void
    {
        $switchConfig = SwitchConfig::factory()->create();

        $resource = new SwitchConfigResource($switchConfig);
        $result = $resource->toArray(Request::create('/'));

        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('name', $result);
        $this->assertArrayHasKey('hostname', $result);
        $this->assertArrayHasKey('type', $result);
        $this->assertArrayHasKey('enabled', $result);
        $this->assertArrayHasKey('port', $result);
        $this->assertArrayHasKey('timeout', $result);
        $this->assertArrayHasKey('created_at', $result);
        $this->assertArrayHasKey('updated_at', $result);
    }

    public function test_to_array_returns_correct_values(): void
    {
        $switchConfig = SwitchConfig::factory()->create([
            'name' => 'Core Switch',
            'hostname' => 'core-sw.example.com',
            'type' => 'cisco',
            'enabled' => true,
            'port' => 22,
            'timeout' => 10,
        ]);

        $resource = new SwitchConfigResource($switchConfig);
        $result = $resource->toArray(Request::create('/'));

        $this->assertSame($switchConfig->id, $result['id']);
        $this->assertSame('Core Switch', $result['name']);
        $this->assertSame('core-sw.example.com', $result['hostname']);
        $this->assertSame('cisco', $result['type']);
        $this->assertTrue($result['enabled']);
        $this->assertSame(22, $result['port']);
        $this->assertSame(10, $result['timeout']);
        $this->assertEquals($switchConfig->created_at, $result['created_at']);
        $this->assertEquals($switchConfig->updated_at, $result['updated_at']);
    }

    public function test_to_array_does_not_expose_credentials(): void
    {
        $switchConfig = SwitchConfig::factory()->create();

        $resource = new SwitchConfigResource($switchConfig);
        $result = $resource->toArray(Request::create('/'));

        $this->assertArrayNotHasKey('username', $result);
        $this->assertArrayNotHasKey('password', $result);
        $this->assertArrayNotHasKey('enable_password', $result);
    }

    public function test_to_array_matches_to_public_array_format(): void
    {
        $switchConfig = SwitchConfig::factory()->create();

        $resource = new SwitchConfigResource($switchConfig);
        $resourceArray = $resource->toArray(Request::create('/'));
        $publicArray = $switchConfig->toPublicArray();

        $this->assertSame(array_keys($publicArray), array_keys($resourceArray));
        $this->assertEquals($publicArray, $resourceArray);
    }

    public function test_resource_collection_returns_array_of_resources(): void
    {
        SwitchConfig::factory()->count(3)->create();

        $collection = SwitchConfigResource::collection(SwitchConfig::all());
        $resolved = $collection->resolve();

        $this->assertCount(3, $resolved);

        foreach ($resolved as $item) {
            $this->assertArrayHasKey('id', $item);
            $this->assertArrayHasKey('name', $item);
            $this->assertArrayHasKey('hostname', $item);
            $this->assertArrayNotHasKey('password', $item);
        }
    }
}
