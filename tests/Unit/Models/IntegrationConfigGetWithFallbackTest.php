<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\IntegrationConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntegrationConfigGetWithFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_db_value_when_set(): void
    {
        IntegrationConfig::setValue('opnsense', 'endpoint', 'https://fw.local');
        $this->assertSame('https://fw.local', IntegrationConfig::getWithFallback('opnsense', 'endpoint', 'default'));
    }

    public function test_returns_default_when_not_in_db(): void
    {
        $this->assertSame('fallback', IntegrationConfig::getWithFallback('opnsense', 'endpoint', 'fallback'));
    }

    public function test_returns_null_default_when_not_in_db(): void
    {
        $this->assertNull(IntegrationConfig::getWithFallback('opnsense', 'endpoint'));
    }
}
