<?php

namespace Tests\Feature;

use App\Models\IntegrationConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PortalControllerConfigTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_portal_reads_ipv6_config_from_integration_config(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        IntegrationConfig::setValue('ipv6', 'detection_endpoint', 'https://ipv6-db.example.com/detect');

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        $response->assertViewHas('ipv6DetectionEndpoint', 'https://ipv6-db.example.com/detect');
    }

    public function test_portal_defaults_when_no_db_config(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        $response->assertViewHas('ipv6DetectionEndpoint', '');
    }
}
