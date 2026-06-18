<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ValueObjects;

use App\Services\ValueObjects\PortTimeSeries;
use PHPUnit\Framework\TestCase;

class PortTimeSeriesTest extends TestCase
{
    public function test_constructs_with_in_and_out_arrays(): void
    {
        $in = [['timestamp' => 1.0, 'value' => 100.0]];
        $out = [['timestamp' => 1.0, 'value' => 50.0]];

        $series = new PortTimeSeries(in: $in, out: $out);

        $this->assertSame($in, $series->in);
        $this->assertSame($out, $series->out);
    }

    public function test_constructs_with_empty_arrays(): void
    {
        $series = new PortTimeSeries(in: [], out: []);

        $this->assertSame([], $series->in);
        $this->assertSame([], $series->out);
    }
}
