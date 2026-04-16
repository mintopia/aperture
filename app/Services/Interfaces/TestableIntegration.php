<?php

declare(strict_types=1);

namespace App\Services\Interfaces;

use App\Services\ValueObjects\TestConnectionResult;

interface TestableIntegration
{
    /**
     * Test connectivity using the supplied configuration array.
     *
     * @param  array<string, mixed>  $config  Merged DB + request config
     */
    public function connect(array $config): TestConnectionResult;
}
