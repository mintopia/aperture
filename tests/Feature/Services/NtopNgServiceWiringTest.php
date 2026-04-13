<?php

namespace Tests\Feature\Services;

use App\Models\IntegrationConfig;
use App\Services\NtopNgService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

class NtopNgServiceWiringTest extends TestCase
{
    use RefreshDatabase;

    public function test_ntopng_service_uses_db_config_over_env(): void
    {
        IntegrationConfig::setValue('ntopng', 'endpoint', 'https://ntopng-db.example.com');
        IntegrationConfig::setValue('ntopng', 'username', 'dbuser');
        IntegrationConfig::setValue('ntopng', 'password', 'dbpass', true);
        IntegrationConfig::setValue('ntopng', 'interface', '7');

        config([
            'aperture.ntopng.endpoint' => 'https://ntopng-env.example.com',
            'aperture.ntopng.username' => 'envuser',
            'aperture.ntopng.password' => 'envpass',
            'aperture.ntopng.interface' => '1',
        ]);

        $this->app->forgetInstance(NtopNgService::class);

        $service = $this->app->make(NtopNgService::class);

        $reflection = new ReflectionClass($service);
        $interfaceProp = $reflection->getProperty('interface');
        $this->assertEquals(7, $interfaceProp->getValue($service));
    }
}
