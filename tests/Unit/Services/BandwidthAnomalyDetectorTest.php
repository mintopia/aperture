<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\BandwidthAnomalyDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BandwidthAnomalyDetectorTest extends TestCase
{
    public static function isAnomalyProvider(): array
    {
        return [
            'detects anomaly when ratio exceeds threshold' => [3.0, 120000.0, 30000.0, true],
            'does not detect anomaly when ratio below threshold' => [3.0, 60000.0, 30000.0, false],
            'detects anomaly at exact threshold' => [3.0, 90000.0, 30000.0, true],
            'does not detect anomaly when long term is zero' => [3.0, 120000.0, 0.0, false],
            'does not detect anomaly when short term is zero' => [3.0, 0.0, 30000.0, false],
            'does not detect anomaly when both values are zero' => [3.0, 0.0, 0.0, false],
            'does not detect anomaly with negative long term' => [3.0, 120000.0, -1.0, false],
            'custom threshold: 4x does not trigger at 5.0' => [5.0, 120000.0, 30000.0, false],
            'custom threshold: 5x triggers at 5.0' => [5.0, 150000.0, 30000.0, true],
        ];
    }

    #[DataProvider('isAnomalyProvider')]
    public function test_is_anomaly(float $threshold, float $shortTermAvg, float $longTermAvg, bool $expected): void
    {
        $detector = new BandwidthAnomalyDetector(threshold: $threshold);

        $result = $detector->isAnomaly(shortTermAvg: $shortTermAvg, longTermAvg: $longTermAvg);

        $this->assertSame($expected, $result);
    }

    public static function calculateRatioProvider(): array
    {
        return [
            'calculates ratio correctly' => [150000.0, 30000.0, 5.0],
            'returns zero when long term is zero' => [150000.0, 0.0, 0.0],
        ];
    }

    #[DataProvider('calculateRatioProvider')]
    public function test_calculate_ratio(float $shortTermAvg, float $longTermAvg, float $expected): void
    {
        $detector = new BandwidthAnomalyDetector(threshold: 3.0);

        $ratio = $detector->calculateRatio(shortTermAvg: $shortTermAvg, longTermAvg: $longTermAvg);

        $this->assertSame($expected, $ratio);
    }

}
