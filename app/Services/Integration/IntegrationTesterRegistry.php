<?php

declare(strict_types=1);

namespace App\Services\Integration;

use App\Services\Interfaces\TestableIntegration;
use InvalidArgumentException;

class IntegrationTesterRegistry
{
    /** @param array<string, TestableIntegration> $testers */
    public function __construct(private array $testers = []) {}

    public function register(string $service, TestableIntegration $tester): void
    {
        $this->testers[$service] = $tester;
    }

    public function get(string $service): TestableIntegration
    {
        return $this->testers[$service] ?? throw new InvalidArgumentException("No tester for: {$service}");
    }

    public function has(string $service): bool
    {
        return isset($this->testers[$service]);
    }
}
