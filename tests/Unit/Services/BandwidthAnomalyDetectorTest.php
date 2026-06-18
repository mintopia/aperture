<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\BandwidthAnomalyDetector;
use PHPUnit\Framework\TestCase;

class BandwidthAnomalyDetectorTest extends TestCase
{
    public function test_detects_anomaly_when_ratio_exceeds_threshold(): void
    {
        $detector = new BandwidthAnomalyDetector(threshold: 3.0);

        $result = $detector->isAnomaly(shortTermAvg: 120000.0, longTermAvg: 30000.0);

        $this->assertTrue($result);
    }

    public function test_does_not_detect_anomaly_when_ratio_below_threshold(): void
    {
        $detector = new BandwidthAnomalyDetector(threshold: 3.0);

        $result = $detector->isAnomaly(shortTermAvg: 60000.0, longTermAvg: 30000.0);

        $this->assertFalse($result);
    }

    public function test_detects_anomaly_at_exact_threshold(): void
    {
        $detector = new BandwidthAnomalyDetector(threshold: 3.0);

        $result = $detector->isAnomaly(shortTermAvg: 90000.0, longTermAvg: 30000.0);

        $this->assertTrue($result);
    }

    public function test_does_not_detect_anomaly_when_long_term_is_zero(): void
    {
        $detector = new BandwidthAnomalyDetector(threshold: 3.0);

        $result = $detector->isAnomaly(shortTermAvg: 120000.0, longTermAvg: 0.0);

        $this->assertFalse($result);
    }

    public function test_does_not_detect_anomaly_when_short_term_is_zero(): void
    {
        $detector = new BandwidthAnomalyDetector(threshold: 3.0);

        $result = $detector->isAnomaly(shortTermAvg: 0.0, longTermAvg: 30000.0);

        $this->assertFalse($result);
    }

    public function test_calculates_ratio_correctly(): void
    {
        $detector = new BandwidthAnomalyDetector(threshold: 3.0);

        $ratio = $detector->calculateRatio(shortTermAvg: 150000.0, longTermAvg: 30000.0);

        $this->assertSame(5.0, $ratio);
    }

    public function test_calculates_ratio_returns_zero_when_long_term_is_zero(): void
    {
        $detector = new BandwidthAnomalyDetector(threshold: 3.0);

        $ratio = $detector->calculateRatio(shortTermAvg: 150000.0, longTermAvg: 0.0);

        $this->assertSame(0.0, $ratio);
    }

    public function test_custom_threshold_is_respected(): void
    {
        $detector = new BandwidthAnomalyDetector(threshold: 5.0);

        // 4x should not trigger at 5.0 threshold
        $this->assertFalse($detector->isAnomaly(shortTermAvg: 120000.0, longTermAvg: 30000.0));

        // 5x should trigger at 5.0 threshold
        $this->assertTrue($detector->isAnomaly(shortTermAvg: 150000.0, longTermAvg: 30000.0));
    }

    public function test_get_threshold_returns_configured_threshold(): void
    {
        $detector = new BandwidthAnomalyDetector(threshold: 4.5);

        $this->assertSame(4.5, $detector->getThreshold());
    }

    public function test_does_not_detect_anomaly_when_both_values_are_zero(): void
    {
        $detector = new BandwidthAnomalyDetector(threshold: 3.0);

        $result = $detector->isAnomaly(shortTermAvg: 0.0, longTermAvg: 0.0);

        $this->assertFalse($result);
    }

    public function test_does_not_detect_anomaly_with_negative_long_term(): void
    {
        $detector = new BandwidthAnomalyDetector(threshold: 3.0);

        $result = $detector->isAnomaly(shortTermAvg: 120000.0, longTermAvg: -1.0);

        $this->assertFalse($result);
    }
}
