<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Resources;

use App\Http\Resources\IpAddressResource;
use App\Models\IpAddress;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class IpAddressResourceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_to_array_returns_expected_keys(): void
    {
        $ip = IpAddress::factory()->create();

        $resource = new IpAddressResource($ip);
        $result = $resource->toArray(Request::create('/'));

        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('address', $result);
        $this->assertArrayHasKey('internet_enabled', $result);
        $this->assertArrayHasKey('rate_limit_enabled', $result);
        $this->assertArrayHasKey('dns_filtering_enabled', $result);
        $this->assertArrayHasKey('last_seen_at', $result);
    }

    public function test_to_array_returns_correct_values(): void
    {
        $ip = IpAddress::factory()->create([
            'address' => '192.168.1.100',
            'internet_enabled' => true,
            'rate_limit_enabled' => false,
            'dns_filtering_enabled' => true,
            'last_seen_at' => '2026-01-15 10:30:00',
        ]);

        $resource = new IpAddressResource($ip);
        $result = $resource->toArray(Request::create('/'));

        $this->assertSame($ip->id, $result['id']);
        $this->assertSame('192.168.1.100', $result['address']);
        $this->assertTrue($result['internet_enabled']);
        $this->assertFalse($result['rate_limit_enabled']);
        $this->assertTrue($result['dns_filtering_enabled']);
    }

    public function test_internet_enabled_ip(): void
    {
        $ip = IpAddress::factory()->internetEnabled()->create();

        $resource = new IpAddressResource($ip);
        $result = $resource->toArray(Request::create('/'));

        $this->assertTrue($result['internet_enabled']);
    }

    public function test_to_array_includes_last_seen_at(): void
    {
        $ip = IpAddress::factory()->create([
            'last_seen_at' => '2026-03-01 12:00:00',
        ]);

        $resource = new IpAddressResource($ip);
        $result = $resource->toArray(Request::create('/'));

        $this->assertNotNull($result['last_seen_at']);
        $this->assertEquals($ip->last_seen_at, $result['last_seen_at']);
    }

    public function test_resource_collection_returns_array_of_resources(): void
    {
        IpAddress::factory()->count(3)->create();

        $collection = IpAddressResource::collection(IpAddress::all());
        $resolved = $collection->resolve();

        $this->assertCount(3, $resolved);

        foreach ($resolved as $item) {
            $this->assertArrayHasKey('id', $item);
            $this->assertArrayHasKey('address', $item);
            $this->assertArrayHasKey('internet_enabled', $item);
        }
    }
}
