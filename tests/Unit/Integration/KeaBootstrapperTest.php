<?php

declare(strict_types=1);

namespace Tests\Unit\Integration;

use App\Integration\IntegrationBootstrapper;
use App\Integration\KeaBootstrapper;
use App\Models\CapabilityAssignment;
use App\Models\IntegrationConfig;
use App\Services\Interfaces\DhcpInterface;
use App\Services\Interfaces\IpMacResolverInterface;
use App\Services\Kea\KeaDhcpService;
use App\Services\Kea\KeaIpMacResolver;
use App\Services\Null\NullDhcpService;
use App\Services\Null\NullIpMacResolver;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class KeaBootstrapperTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_implements_interface(): void
    {
        $this->assertInstanceOf(IntegrationBootstrapper::class, new KeaBootstrapper);
    }

    public function test_binds_kea_dhcp_service_when_kea_is_active_dhcp_provider(): void
    {
        Queue::fake();
        CapabilityAssignment::assign('dhcp', 'kea');
        IntegrationConfig::setValue('kea', 'endpoint_v4', 'https://kea.local');

        $this->app->bind(DhcpInterface::class, NullDhcpService::class);

        (new KeaBootstrapper)->register($this->app);

        $this->assertInstanceOf(KeaDhcpService::class, $this->app->make(DhcpInterface::class));
    }

    public function test_falls_through_to_null_service_when_kea_is_active_dhcp_provider_but_endpoint_not_configured(): void
    {
        Queue::fake();
        CapabilityAssignment::assign('dhcp', 'kea');

        $this->app->bind(DhcpInterface::class, NullDhcpService::class);

        (new KeaBootstrapper)->register($this->app);

        $service = $this->app->make(DhcpInterface::class);

        $this->assertInstanceOf(NullDhcpService::class, $service);
        $this->assertNotInstanceOf(KeaDhcpService::class, $service);
    }

    public function test_falls_through_to_previous_service_when_endpoint_configured_but_blank(): void
    {
        Queue::fake();
        CapabilityAssignment::assign('dhcp', 'kea');
        IntegrationConfig::setValue('kea', 'endpoint_v4', '');

        $this->app->bind(DhcpInterface::class, NullDhcpService::class);

        (new KeaBootstrapper)->register($this->app);

        $service = $this->app->make(DhcpInterface::class);

        $this->assertInstanceOf(NullDhcpService::class, $service);
        $this->assertNotInstanceOf(KeaDhcpService::class, $service);
    }

    public function test_kea_dhcp_service_is_wired_to_configured_endpoint_and_credentials(): void
    {
        Queue::fake();
        Http::fake([
            'kea.local' => Http::response([
                ['result' => 3, 'text' => 'no leases found'],
            ]),
        ]);
        CapabilityAssignment::assign('dhcp', 'kea');
        IntegrationConfig::setValue('kea', 'endpoint_v4', 'https://kea.local');
        IntegrationConfig::setValue('kea', 'username_v4', 'admin');
        IntegrationConfig::setValue('kea', 'password_v4', 'secret', encrypted: true);

        $this->app->bind(DhcpInterface::class, NullDhcpService::class);

        (new KeaBootstrapper)->register($this->app);

        $service = $this->app->make(DhcpInterface::class);
        $this->assertInstanceOf(KeaDhcpService::class, $service);

        $this->assertNull($service->getLease('192.168.1.50'));

        Http::assertSent(function ($request): bool {
            $auth = $request->header('Authorization');

            return $request->url() === 'https://kea.local'
                && ! empty($auth)
                && str_starts_with($auth[0], 'Basic ');
        });
    }

    public function test_falls_through_when_kea_config_lookup_throws(): void
    {
        Queue::fake();
        CapabilityAssignment::assign('dhcp', 'kea');
        Schema::drop('integration_configs');

        $this->app->bind(DhcpInterface::class, NullDhcpService::class);

        (new KeaBootstrapper)->register($this->app);

        $service = $this->app->make(DhcpInterface::class);

        $this->assertInstanceOf(NullDhcpService::class, $service);
        $this->assertNotInstanceOf(KeaDhcpService::class, $service);
    }

    public function test_falls_through_to_previous_service_when_kea_is_not_active_dhcp_provider(): void
    {
        Queue::fake();
        CapabilityAssignment::assign('dhcp', 'opnsense');

        $this->app->bind(DhcpInterface::class, NullDhcpService::class);

        (new KeaBootstrapper)->register($this->app);

        $service = $this->app->make(DhcpInterface::class);

        $this->assertInstanceOf(NullDhcpService::class, $service);
        $this->assertNotInstanceOf(KeaDhcpService::class, $service);
    }

    public function test_binds_kea_ip_mac_resolver_when_kea_is_active_ip_mac_provider(): void
    {
        Queue::fake();
        CapabilityAssignment::assign('ip-mac', 'kea');

        $this->app->bind(IpMacResolverInterface::class, NullIpMacResolver::class);

        (new KeaBootstrapper)->register($this->app);

        $this->assertInstanceOf(KeaIpMacResolver::class, $this->app->make(IpMacResolverInterface::class));
    }

    public function test_falls_through_to_previous_resolver_when_kea_is_not_active_ip_mac_provider(): void
    {
        Queue::fake();
        CapabilityAssignment::assign('ip-mac', 'librenms');

        $this->app->bind(IpMacResolverInterface::class, NullIpMacResolver::class);

        (new KeaBootstrapper)->register($this->app);

        $resolver = $this->app->make(IpMacResolverInterface::class);

        $this->assertInstanceOf(NullIpMacResolver::class, $resolver);
        $this->assertNotInstanceOf(KeaIpMacResolver::class, $resolver);
    }

    public function test_falls_through_when_no_capability_assignment_exists_at_all(): void
    {
        $this->app->bind(DhcpInterface::class, NullDhcpService::class);

        (new KeaBootstrapper)->register($this->app);

        $service = $this->app->make(DhcpInterface::class);

        $this->assertInstanceOf(NullDhcpService::class, $service);
    }

    public function test_falls_through_when_capability_assignment_lookup_throws(): void
    {
        Schema::drop('capability_assignments');

        $this->app->bind(DhcpInterface::class, NullDhcpService::class);
        $this->app->bind(IpMacResolverInterface::class, NullIpMacResolver::class);

        (new KeaBootstrapper)->register($this->app);

        $this->assertInstanceOf(NullDhcpService::class, $this->app->make(DhcpInterface::class));
        $this->assertInstanceOf(NullIpMacResolver::class, $this->app->make(IpMacResolverInterface::class));
    }
}
