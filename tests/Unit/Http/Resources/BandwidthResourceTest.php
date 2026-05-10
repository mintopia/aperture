<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Resources;

use App\Http\Resources\BandwidthResource;
use App\Services\ValueObjects\IpBandwidthResult;
use Tests\TestCase;

class BandwidthResourceTest extends TestCase
{
    public function test_resource_returns_correct_json_structure(): void
    {
        $result = new IpBandwidthResult(
            received: 1024,
            sent: 2048,
            timestamps: ['2024-01-01T00:00:00Z'],
            download: [100.0],
            upload: [200.0],
        );

        $resource = new BandwidthResource($result);
        $json = $resource->toArray(request());

        $this->assertSame(1024, $json['totalReceived']);
        $this->assertSame(2048, $json['totalSent']);
        $this->assertSame(['2024-01-01T00:00:00Z'], $json['timestamps']);
        $this->assertSame([100.0], $json['download']);
        $this->assertSame([200.0], $json['upload']);
    }

    public function test_resource_wraps_all_fields(): void
    {
        $result = new IpBandwidthResult(
            received: 0,
            sent: 0,
            timestamps: [],
            download: [],
            upload: [],
        );

        $resource = new BandwidthResource($result);
        $json = $resource->toArray(request());

        $this->assertArrayHasKey('timestamps', $json);
        $this->assertArrayHasKey('download', $json);
        $this->assertArrayHasKey('upload', $json);
        $this->assertArrayHasKey('totalReceived', $json);
        $this->assertArrayHasKey('totalSent', $json);
        $this->assertCount(5, $json);
    }
}
