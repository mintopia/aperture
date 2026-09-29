<?php

declare(strict_types=1);

namespace Tests\Unit\Integration;

use App\Enums\Capability;
use App\Integration\IntegrationBootstrapper;
use App\Integration\KeaBootstrapper;
use App\Models\CapabilityAssignment;
use App\Models\IntegrationConfig;
use App\Services\Interfaces\DhcpInterface;
use App\Services\Interfaces\IpMacResolverInterface;
use App\Services\Kea\KeaDhcpService;
use App\Services\Kea\KeaIpMacResolver;
use App\Services\LibreNms\LibreNmsIpMacResolver;
use App\Services\Null\NullDhcpService;
use App\Services\Null\NullIpMacResolver;
use App\Services\OpnSense\OpnSenseDhcpService;
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
        CapabilityAssignment::assign(Capability::Dhcp, 'kea');
        IntegrationConfig::setValue('kea', 'endpoint_v4', 'https://kea.local');

        $this->assertInstanceOf(KeaDhcpService::class, $this->app->make(DhcpInterface::class));
    }

    public function test_falls_through_to_null_service_when_kea_is_active_dhcp_provider_but_endpoint_not_configured(): void
    {
        Queue::fake();
        CapabilityAssignment::assign(Capability::Dhcp, 'kea');

        $service = $this->app->make(DhcpInterface::class);

        $this->assertInstanceOf(NullDhcpService::class, $service);
        $this->assertNotInstanceOf(KeaDhcpService::class, $service);
    }

    public function test_falls_through_to_previous_service_when_endpoint_configured_but_blank(): void
    {
        Queue::fake();
        CapabilityAssignment::assign(Capability::Dhcp, 'kea');
        IntegrationConfig::setValue('kea', 'endpoint_v4', '');

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
        CapabilityAssignment::assign(Capability::Dhcp, 'kea');
        IntegrationConfig::setValue('kea', 'endpoint_v4', 'https://kea.local');
        IntegrationConfig::setValue('kea', 'username_v4', 'admin');
        IntegrationConfig::setValue('kea', 'password_v4', 'secret', encrypted: true);

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

    public function test_kea_dhcp_service_is_wired_when_only_ipv6_endpoint_configured(): void
    {
        Queue::fake();
        Http::fake([
            'kea6.local' => Http::response([
                ['result' => 3, 'text' => 'no leases found'],
            ]),
        ]);
        CapabilityAssignment::assign(Capability::Dhcp, 'kea');
        IntegrationConfig::setValue('kea', 'endpoint_v6', 'https://kea6.local');
        IntegrationConfig::setValue('kea', 'username_v6', 'admin');
        IntegrationConfig::setValue('kea', 'password_v6', 'secret', encrypted: true);

        $service = $this->app->make(DhcpInterface::class);
        $this->assertInstanceOf(KeaDhcpService::class, $service);

        $this->assertNull($service->getLease('192.168.1.50'));
        Http::assertNothingSent();

        $service->snapshot();

        Http::assertSent(function ($request): bool {
            $data = $request->data();
            $auth = $request->header('Authorization');

            return $request->url() === 'https://kea6.local'
                && $data['command'] === 'lease6-get-page'
                && $data['service'] === ['dhcp6']
                && ! empty($auth)
                && str_starts_with($auth[0], 'Basic ');
        });
    }

    public function test_kea_dhcp_service_is_wired_to_both_endpoints_when_both_configured(): void
    {
        Queue::fake();
        Http::fake([
            'kea4.local' => Http::response([['result' => 3]]),
            'kea6.local' => Http::response([['result' => 3]]),
        ]);
        CapabilityAssignment::assign(Capability::Dhcp, 'kea');
        IntegrationConfig::setValue('kea', 'endpoint_v4', 'https://kea4.local');
        IntegrationConfig::setValue('kea', 'endpoint_v6', 'https://kea6.local');
        IntegrationConfig::setValue('kea', 'username_v6', 'admin');
        IntegrationConfig::setValue('kea', 'password_v6', 'secret', encrypted: true);

        $service = $this->app->make(DhcpInterface::class);
        $this->assertInstanceOf(KeaDhcpService::class, $service);

        $service->snapshot();

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return $request->url() === 'https://kea4.local'
                && $data['command'] === 'lease4-get-page'
                && $data['service'] === ['dhcp4'];
        });

        Http::assertSent(function ($request): bool {
            $data = $request->data();
            $auth = $request->header('Authorization');

            return $request->url() === 'https://kea6.local'
                && $data['command'] === 'lease6-get-page'
                && $data['service'] === ['dhcp6']
                && ! empty($auth)
                && str_starts_with($auth[0], 'Basic ');
        });
    }

    public function test_falls_through_when_kea_config_lookup_throws(): void
    {
        Queue::fake();
        CapabilityAssignment::assign(Capability::Dhcp, 'kea');
        Schema::drop('integration_configs');

        $service = $this->app->make(DhcpInterface::class);

        $this->assertInstanceOf(NullDhcpService::class, $service);
        $this->assertNotInstanceOf(KeaDhcpService::class, $service);
    }

    public function test_resolves_other_provider_when_kea_is_not_active_dhcp_provider(): void
    {
        Queue::fake();
        CapabilityAssignment::assign(Capability::Dhcp, 'opnsense');

        $service = $this->app->make(DhcpInterface::class);

        $this->assertInstanceOf(OpnSenseDhcpService::class, $service);
        $this->assertNotInstanceOf(KeaDhcpService::class, $service);
    }

    public function test_binds_kea_ip_mac_resolver_when_kea_is_active_ip_mac_provider(): void
    {
        Queue::fake();
        CapabilityAssignment::assign(Capability::IpMac, 'kea');

        $this->assertInstanceOf(KeaIpMacResolver::class, $this->app->make(IpMacResolverInterface::class));
    }

    public function test_resolves_other_provider_when_kea_is_not_active_ip_mac_provider(): void
    {
        Queue::fake();
        CapabilityAssignment::assign(Capability::IpMac, 'librenms');

        $resolver = $this->app->make(IpMacResolverInterface::class);

        $this->assertInstanceOf(LibreNmsIpMacResolver::class, $resolver);
        $this->assertNotInstanceOf(KeaIpMacResolver::class, $resolver);
    }

    public function test_falls_through_when_no_capability_assignment_exists_at_all(): void
    {

        $service = $this->app->make(DhcpInterface::class);

        $this->assertInstanceOf(NullDhcpService::class, $service);
    }

    public function test_falls_through_when_capability_assignment_lookup_throws(): void
    {
        Schema::drop('capability_assignments');

        $this->assertInstanceOf(NullDhcpService::class, $this->app->make(DhcpInterface::class));
        $this->assertInstanceOf(NullIpMacResolver::class, $this->app->make(IpMacResolverInterface::class));
    }
}
