<?php

namespace Tests\Feature\Services;

use App\Models\CapabilityAssignment;
use App\Models\IntegrationConfig;
use App\Services\Interfaces\HostStatsProviderInterface;
use App\Services\NtopNgService;
use App\Services\Null\NullHostStatsProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

class NtopNgServiceWiringTest extends TestCase
{
    use RefreshDatabase;

    public function test_host_stats_provider_uses_ntopng_when_capability_assigned(): void
    {
        IntegrationConfig::setValue('ntopng', 'endpoint', 'https://ntopng-db.example.com');
        IntegrationConfig::setValue('ntopng', 'username', 'dbuser');
        IntegrationConfig::setValue('ntopng', 'password', 'dbpass', true);
        IntegrationConfig::setValue('ntopng', 'interface', '7');
        CapabilityAssignment::assign('host-stats', 'ntopng');

        $this->app->forgetInstance(HostStatsProviderInterface::class);

        $service = $this->app->make(HostStatsProviderInterface::class);

        $this->assertInstanceOf(NtopNgService::class, $service);

        $reflection = new ReflectionClass($service);
        $interfaceProp = $reflection->getProperty('interface');
        $this->assertEquals(7, $interfaceProp->getValue($service));
    }

    public function test_host_stats_provider_returns_null_provider_when_no_capability(): void
    {
        $this->app->forgetInstance(HostStatsProviderInterface::class);

        $service = $this->app->make(HostStatsProviderInterface::class);

        $this->assertInstanceOf(NullHostStatsProvider::class, $service);
    }
}
