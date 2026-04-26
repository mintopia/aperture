<?php

namespace Tests\Unit\Services\ValueObjects;

use App\Services\ValueObjects\IpBandwidthResult;
use PHPUnit\Framework\TestCase;

class IpBandwidthResultTest extends TestCase
{
    public function test_constructs_with_all_fields(): void
    {
        $result = new IpBandwidthResult(
            received: 1024,
            sent: 512,
            timestamps: ['1000', '1060'],
            download: [100.0, 200.0],
            upload: [50.0, 75.0],
        );

        $this->assertSame(1024, $result->received);
        $this->assertSame(512, $result->sent);
        $this->assertSame(['1000', '1060'], $result->timestamps);
        $this->assertSame([100.0, 200.0], $result->download);
        $this->assertSame([50.0, 75.0], $result->upload);
    }

    public function test_constructs_with_empty_arrays(): void
    {
        $result = new IpBandwidthResult(
            received: 0,
            sent: 0,
            timestamps: [],
            download: [],
            upload: [],
        );

        $this->assertSame(0, $result->received);
        $this->assertSame([], $result->timestamps);
    }
}
