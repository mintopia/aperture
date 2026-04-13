<?php

namespace Tests\Feature;

use App\Models\IntegrationConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PortalControllerConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_portal_reads_ipv6_config_from_integration_config(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        IntegrationConfig::setValue('ipv6', 'detection_enabled', '1');
        IntegrationConfig::setValue('ipv6', 'detection_endpoint', 'https://ipv6-db.example.com/detect');

        config(['aperture.ipv6.detection_enabled' => false]);
        config(['aperture.ipv6.detection_endpoint' => '']);

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        $response->assertViewHas('ipv6DetectionEnabled', true);
        $response->assertViewHas('ipv6DetectionEndpoint', 'https://ipv6-db.example.com/detect');
    }

    public function test_portal_falls_back_to_env_when_no_db_config(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        config(['aperture.ipv6.detection_enabled' => true]);
        config(['aperture.ipv6.detection_endpoint' => 'https://ipv6-env.example.com']);

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        $response->assertViewHas('ipv6DetectionEnabled', true);
        $response->assertViewHas('ipv6DetectionEndpoint', 'https://ipv6-env.example.com');
    }
}
