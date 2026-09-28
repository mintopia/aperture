<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dhcp;

use App\Models\CapabilityAssignment;
use App\Models\IntegrationConfig;
use App\Services\Interfaces\DhcpInterface;
use App\Services\OpnSense\OpnSenseClient;
use App\Services\OpnSense\OpnSenseDhcpService;
use GuzzleHttp\Client;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use ReflectionProperty;
use Tests\TestCase;

class OpnSenseHttpConfigTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function guzzleOf(object $owner): Client
    {
        $client = (new ReflectionProperty($owner, 'client'))->getValue($owner);
        $this->assertInstanceOf(Client::class, $client);

        return $client;
    }

    public function test_shared_opnsense_client_has_request_and_connect_timeouts(): void
    {
        IntegrationConfig::setValue('opnsense', 'endpoint', 'https://opnsense.local');
        $this->app->forgetInstance(OpnSenseClient::class);

        $guzzle = $this->guzzleOf($this->app->make(OpnSenseClient::class));

        $this->assertGreaterThan(0, $guzzle->getConfig('timeout'));
        $this->assertGreaterThan(0, $guzzle->getConfig('connect_timeout'));
    }

    public function test_dhcp_service_client_has_request_and_connect_timeouts(): void
    {
        CapabilityAssignment::assign('dhcp', 'opnsense');
        IntegrationConfig::setValue('opnsense', 'endpoint', 'https://opnsense.local');
        $this->app->forgetInstance(DhcpInterface::class);

        $service = $this->app->make(DhcpInterface::class);
        $this->assertInstanceOf(OpnSenseDhcpService::class, $service);
        $guzzle = $this->guzzleOf($service);

        $this->assertGreaterThan(0, $guzzle->getConfig('timeout'));
        $this->assertGreaterThan(0, $guzzle->getConfig('connect_timeout'));
    }

    public function test_isc_ipv6_ranges_do_not_point_at_the_leases_endpoint(): void
    {
        CapabilityAssignment::assign('dhcp', 'opnsense');
        IntegrationConfig::setValue('opnsense', 'dhcp_server', 'isc');
        IntegrationConfig::setValue('opnsense', 'endpoint', 'https://opnsense.local');
        $this->app->forgetInstance(DhcpInterface::class);

        $service = $this->app->make(DhcpInterface::class);
        $path = (new ReflectionProperty($service, 'ipv6RangesPath'))->getValue($service);

        $this->assertStringNotContainsString('leases', (string) $path);
    }
}
