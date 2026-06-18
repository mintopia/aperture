<?php

declare(strict_types=1);

namespace App\Services;

class BandwidthAnomalyDetector
{
    public function __construct(
        protected float $threshold = 3.0,
    ) {}

    /**
     * Determine if the short-term average constitutes an anomaly
     * relative to the long-term average.
     */
    public function isAnomaly(float $shortTermAvg, float $longTermAvg): bool
    {
        if ($longTermAvg <= 0.0 || $shortTermAvg <= 0.0) {
            return false;
        }

        return $this->calculateRatio($shortTermAvg, $longTermAvg) >= $this->threshold;
    }

    /**
     * Calculate the ratio of short-term to long-term average.
     *
     * Returns 0.0 when the long-term average is zero to avoid division by zero.
     */
    public function calculateRatio(float $shortTermAvg, float $longTermAvg): float
    {
        if ($longTermAvg <= 0.0) {
            return 0.0;
        }

        return $shortTermAvg / $longTermAvg;
    }

    /**
     * Get the configured threshold.
     */
    public function getThreshold(): float
    {
        return $this->threshold;
    }
}
