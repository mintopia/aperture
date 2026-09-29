<?php

declare(strict_types=1);

namespace App\Services;

class BandwidthAnomalyDetector
{
    public function __construct(
        protected float $threshold = 3.0,
    ) {}

    public function isAnomaly(float $shortTermAvg, float $longTermAvg): bool
    {
        if ($longTermAvg <= 0.0 || $shortTermAvg <= 0.0) {
            return false;
        }

        return $this->calculateRatio($shortTermAvg, $longTermAvg) >= $this->threshold;
    }

    public function calculateRatio(float $shortTermAvg, float $longTermAvg): float
    {
        if ($longTermAvg <= 0.0) {
            return 0.0;
        }

        return $shortTermAvg / $longTermAvg;
    }
}
