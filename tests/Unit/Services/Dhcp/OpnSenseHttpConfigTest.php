<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dhcp;

use App\Enums\Capability;
use App\Models\CapabilityAssignment;
use App\Models\IntegrationConfig;
use App\Services\Interfaces\DhcpInterface;
use App\Services\OpnSense\OpnSenseClient;
use App\Services\OpnSense\OpnSenseDhcpService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use ReflectionProperty;
use Tests\TestCase;
use Throwable;

class OpnSenseHttpConfigTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function captureOptions(callable $call): array
    {
        $captured = [];
        Http::fake(function (Request $request, array $options) use (&$captured) {
            $captured = $options;

            return Http::response(['rows' => []]);
        });

        try {
            $call();
        } catch (Throwable) {
        }

        return $captured;
    }

    public function test_shared_opnsense_client_has_request_and_connect_timeouts(): void
    {
        config(['services.external_http.timeout' => 7, 'services.external_http.connect_timeout' => 3]);
        IntegrationConfig::setValue('opnsense', 'endpoint', 'https://opnsense.local');
        $this->app->forgetInstance(OpnSenseClient::class);

        $client = $this->app->make(OpnSenseClient::class);
        $options = $this->captureOptions(fn () => $client->get('/api/core/firmware/status'));

        $this->assertSame(7, $options['timeout']);
        $this->assertSame(3, $options['connect_timeout']);
    }

    public function test_dhcp_service_client_has_request_and_connect_timeouts(): void
    {
        config(['services.external_http.timeout' => 7, 'services.external_http.connect_timeout' => 3]);
        CapabilityAssignment::assign(Capability::Dhcp, 'opnsense');
        IntegrationConfig::setValue('opnsense', 'endpoint', 'https://opnsense.local');
        $this->app->forgetInstance(DhcpInterface::class);

        $options = $this->captureOptions(function (): Collection {
            $service = $this->app->make(DhcpInterface::class);
            $this->assertInstanceOf(OpnSenseDhcpService::class, $service);

            return $service->snapshot()->leases;
        });

        $this->assertSame(7, $options['timeout']);
        $this->assertSame(3, $options['connect_timeout']);
    }

    public function test_isc_ipv6_ranges_do_not_point_at_the_leases_endpoint(): void
    {
        CapabilityAssignment::assign(Capability::Dhcp, 'opnsense');
        IntegrationConfig::setValue('opnsense', 'dhcp_server', 'isc');
        IntegrationConfig::setValue('opnsense', 'endpoint', 'https://opnsense.local');
        $this->app->forgetInstance(DhcpInterface::class);

        $service = $this->app->make(DhcpInterface::class);
        $path = (new ReflectionProperty($service, 'ipv6RangesPath'))->getValue($service);

        $this->assertStringNotContainsString('leases', (string) $path);
    }
}
