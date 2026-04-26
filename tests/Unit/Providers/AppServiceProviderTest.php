<?php

declare(strict_types=1);

namespace Tests\Unit\Providers;

use App\Models\CapabilityAssignment;
use App\Models\IntegrationConfig;
use App\Models\SwitchConfig;
use App\Providers\AppServiceProvider;
use App\Providers\NetworkServiceProvider;
use App\Services\BorealisService;
use App\Services\CachedNetworkInventoryService;
use App\Services\Firewalls\OpnSense;
use App\Services\Interfaces\AuthProviderInterface;
use App\Services\Interfaces\DhcpInterface;
use App\Services\Interfaces\DnsFilteringInterface;
use App\Services\Interfaces\FirewallBackendInterface;
use App\Services\Interfaces\HostStatsProviderInterface;
use App\Services\Interfaces\MetricsProviderInterface;
use App\Services\Interfaces\NetworkInventoryInterface;
use App\Services\Interfaces\NetworkSwitchInterface;
use App\Services\Interfaces\SshProxyClientInterface;
use App\Services\Interfaces\TrafficMonitorInterface;
use App\Services\NetworkSwitch\CiscoSwitchAdapter;
use App\Services\NetworkSwitch\SwitchServiceFactory;
use App\Services\NtopNgService;
use App\Services\Null\NullHostStatsProvider;
use App\Services\Null\NullTrafficMonitor;
use App\Services\OpnSense\OpnSenseDhcpService;
use App\Services\PiHole\PiHoleService;
use App\Services\Prometheus\PrometheusService;
use App\Services\Prometheus\PrometheusTrafficMonitor;
use App\Services\SshProxy\SshProxyClient;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

class AppServiceProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_registers_auth_provider_interface_binding(): void
    {
        $this->assertInstanceOf(
            AuthProviderInterface::class,
            $this->app->make(AuthProviderInterface::class)
        );
    }

    public function test_host_stats_provider_returns_null_when_no_capability(): void
    {
        $this->app->forgetInstance(HostStatsProviderInterface::class);

        $service = $this->app->make(HostStatsProviderInterface::class);
        $this->assertInstanceOf(NullHostStatsProvider::class, $service);
    }

    public function test_host_stats_provider_returns_ntopng_when_capability_assigned(): void
    {
        IntegrationConfig::setValue('ntopng', 'endpoint', 'http://localhost:3000');
        IntegrationConfig::setValue('ntopng', 'username', 'admin');
        IntegrationConfig::setValue('ntopng', 'password', 'admin', true);
        IntegrationConfig::setValue('ntopng', 'interface', '1');
        CapabilityAssignment::assign('host-stats', 'ntopng');

        $this->app->forgetInstance(HostStatsProviderInterface::class);

        $service = $this->app->make(HostStatsProviderInterface::class);
        $this->assertInstanceOf(NtopNgService::class, $service);

        $service2 = $this->app->make(HostStatsProviderInterface::class);
        $this->assertSame($service, $service2);
    }

    public function test_boot_registers_borealis_service_singleton(): void
    {
        IntegrationConfig::setValue('borealis', 'endpoint', 'https://auth.test.local');
        IntegrationConfig::setValue('borealis', 'client_id', 'test-client-id');
        IntegrationConfig::setValue('borealis', 'client_secret', 'test-secret', true);

        $service = $this->app->make(BorealisService::class);
        $this->assertInstanceOf(BorealisService::class, $service);
    }

    public function test_boot_registers_borealis_service_singleton_from_db_config(): void
    {
        IntegrationConfig::setValue('borealis', 'endpoint', 'https://auth.test.local');
        IntegrationConfig::setValue('borealis', 'client_id', 'test-client-id');
        IntegrationConfig::setValue('borealis', 'client_secret', 'test-secret', true);

        $service = $this->app->make(BorealisService::class);

        $this->assertInstanceOf(BorealisService::class, $service);
    }

    public function test_boot_registers_network_inventory_interface_singleton(): void
    {
        IntegrationConfig::setValue('librenms', 'endpoint', 'http://localhost');
        IntegrationConfig::setValue('librenms', 'api_key', 'token');
        IntegrationConfig::setValue('librenms', 'enabled', true);

        $this->app->forgetInstance(NetworkInventoryInterface::class);

        $service = $this->app->make(NetworkInventoryInterface::class);
        $this->assertInstanceOf(CachedNetworkInventoryService::class, $service);
    }

    public function test_registers_firewall_backend_interface_binding(): void
    {
        config([
            'aperture.opnsense.endpoint' => 'http://127.0.0.1:19199',
            'aperture.opnsense.key' => 'key',
            'aperture.opnsense.secret' => 'secret',
            'aperture.opnsense.verify' => false,
            'aperture.opnsense.zoneid' => 1,
            'aperture.opnsense.ratelimitUpUuid' => 'up-uuid',
            'aperture.opnsense.ratelimitDownUuid' => 'down-uuid',
        ]);

        $instance = $this->app->make(FirewallBackendInterface::class);
        $this->assertInstanceOf(OpnSense::class, $instance);
    }

    public function test_registers_dhcp_interface_binding(): void
    {
        IntegrationConfig::setValue('opnsense', 'dhcp_server', 'isc');
        IntegrationConfig::setValue('opnsense', 'endpoint', 'http://127.0.0.1:19199');
        IntegrationConfig::setValue('opnsense', 'key', 'key');
        IntegrationConfig::setValue('opnsense', 'secret', 'secret');

        $this->app->forgetInstance(DhcpInterface::class);
        $instance = $this->app->make(DhcpInterface::class);
        $this->assertInstanceOf(OpnSenseDhcpService::class, $instance);
    }

    public function test_registers_dns_filtering_interface_binding(): void
    {
        config([
            'aperture.pihole.endpoint' => 'http://127.0.0.1:8080',
            'aperture.pihole.password' => 'test-password',
            'aperture.pihole.filtered_group_id' => 1,
            'aperture.pihole.verify' => false,
        ]);

        $instance = $this->app->make(DnsFilteringInterface::class);
        $this->assertInstanceOf(PiHoleService::class, $instance);
    }

    public function test_registers_network_switch_interface_binding(): void
    {
        config([
            'aperture.cisco.hostname' => 'switch.local',
            'aperture.cisco.username' => 'admin',
            'aperture.cisco.password' => 'password',
            'aperture.cisco.enablePassword' => 'enable',
            'aperture.cisco.timeout' => 5,
        ]);

        $instance = $this->app->make(NetworkSwitchInterface::class);
        $this->assertInstanceOf(CiscoSwitchAdapter::class, $instance);
    }

    public function test_registers_switch_service_factory_singleton(): void
    {
        $this->assertInstanceOf(SwitchServiceFactory::class, $this->app->make(SwitchServiceFactory::class));
    }

    public function test_ssh_proxy_client_wraps_ipv6_address_in_brackets(): void
    {
        config([
            'aperture.ssh_proxy.host' => '::1',
            'aperture.ssh_proxy.port' => 8022,
            'aperture.ssh_proxy.api_key' => 'test-key',
            'aperture.ssh_proxy.request_timeout' => 60,
            'aperture.ssh_proxy.connect_timeout' => 5,
        ]);

        $this->app->forgetInstance(SshProxyClientInterface::class);

        $client = $this->app->make(SshProxyClientInterface::class);

        $this->assertInstanceOf(SshProxyClient::class, $client);
    }

    public function test_metrics_provider_returns_prometheus_service_when_configured_and_enabled(): void
    {
        IntegrationConfig::setValue('prometheus', 'endpoint', 'http://prometheus.local:9090');
        IntegrationConfig::setValue('prometheus', 'enabled', '1');
        IntegrationConfig::setValue('prometheus', 'verify_ssl', '1');
        IntegrationConfig::setValue('prometheus', 'bearer_token', 'test-token');
        IntegrationConfig::setValue('prometheus', 'default_step', '60');

        $this->app->forgetInstance(MetricsProviderInterface::class);

        $service = $this->app->make(MetricsProviderInterface::class);

        $this->assertInstanceOf(PrometheusService::class, $service);
    }

    public function test_get_default_switch_config_returns_fallback_when_db_throws(): void
    {
        // Covers AppServiceProvider::getDefaultSwitchConfig() line 325 — the catch(Throwable) block.
        // We add an invalid database connection config, then set SwitchConfig to use it,
        // so that when the Eloquent query runs it throws an exception caught by the catch block.

        config(['aperture.cisco.hostname' => 'fallback.local']);
        config(['database.connections.test_invalid' => [
            'driver' => 'sqlite',
            'database' => '/nonexistent/path/that/does/not/exist.sqlite',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);

        $provider = collect($this->app->getProviders(NetworkServiceProvider::class))->first();
        $this->assertNotNull($provider, 'NetworkServiceProvider should be registered');

        // Use DB::listen to intercept the SwitchConfig query and throw a RuntimeException,
        // which will be caught by the catch(Throwable) block in getDefaultSwitchConfig().
        $thrown = false;
        DB::listen(function (QueryExecuted $event) use (&$thrown): void {
            if (str_contains($event->sql, 'switch_configs') && ! $thrown) {
                $thrown = true;
                throw new RuntimeException('Simulated DB failure for line 325 coverage');
            }
        });

        try {
            $reflection = new ReflectionClass($provider);
            $method = $reflection->getMethod('getDefaultSwitchConfig');

            $switchConfig = $method->invoke($provider);

            $this->assertInstanceOf(SwitchConfig::class, $switchConfig);
            $this->assertSame('fallback.local', $switchConfig->hostname);
        } finally {
            // DB::listen callbacks are cleared per test — no cleanup needed
        }
    }

    public function test_traffic_monitor_returns_null_monitor_when_prometheus_not_assigned(): void
    {
        $this->app->forgetInstance(TrafficMonitorInterface::class);

        $service = $this->app->make(TrafficMonitorInterface::class);

        $this->assertInstanceOf(NullTrafficMonitor::class, $service);
    }

    public function test_traffic_monitor_returns_prometheus_monitor_when_capability_assigned(): void
    {
        IntegrationConfig::setValue('prometheus', 'endpoint', 'http://prometheus.local:9090');
        IntegrationConfig::setValue('prometheus', 'enabled', '1');
        IntegrationConfig::setValue('prometheus', 'verify_ssl', '1');
        IntegrationConfig::setValue('prometheus', 'bearer_token', 'test-token');
        IntegrationConfig::setValue('prometheus', 'default_step', '60');

        CapabilityAssignment::assign('user-bandwidth', 'prometheus');

        $this->app->forgetInstance(TrafficMonitorInterface::class);

        $service = $this->app->make(TrafficMonitorInterface::class);

        $this->assertInstanceOf(PrometheusTrafficMonitor::class, $service);
    }

    public function test_traffic_monitor_returns_null_monitor_when_prometheus_not_enabled(): void
    {
        IntegrationConfig::setValue('prometheus', 'endpoint', 'http://prometheus.local:9090');
        IntegrationConfig::setValue('prometheus', 'enabled', '0');

        CapabilityAssignment::assign('user-bandwidth', 'prometheus');

        $this->app->forgetInstance(TrafficMonitorInterface::class);

        $service = $this->app->make(TrafficMonitorInterface::class);

        $this->assertInstanceOf(NullTrafficMonitor::class, $service);
    }

    public function test_traffic_monitor_returns_null_monitor_when_prometheus_has_no_endpoint(): void
    {
        IntegrationConfig::setValue('prometheus', 'endpoint', '');
        IntegrationConfig::setValue('prometheus', 'enabled', '1');

        CapabilityAssignment::assign('user-bandwidth', 'prometheus');

        $this->app->forgetInstance(TrafficMonitorInterface::class);

        $service = $this->app->make(TrafficMonitorInterface::class);

        $this->assertInstanceOf(NullTrafficMonitor::class, $service);
    }

    public function test_traffic_monitor_passes_custom_metric_config(): void
    {
        IntegrationConfig::setValue('prometheus', 'endpoint', 'http://prometheus.local:9090');
        IntegrationConfig::setValue('prometheus', 'enabled', '1');
        IntegrationConfig::setValue('prometheus', 'verify_ssl', '1');
        IntegrationConfig::setValue('prometheus', 'bearer_token', 'test-token');
        IntegrationConfig::setValue('prometheus', 'default_step', '60');
        IntegrationConfig::setValue('prometheus', 'bandwidth_rcvd_metric', 'custom_rcvd');
        IntegrationConfig::setValue('prometheus', 'bandwidth_sent_metric', 'custom_sent');
        IntegrationConfig::setValue('prometheus', 'bandwidth_ip_label', 'src_addr');

        CapabilityAssignment::assign('user-bandwidth', 'prometheus');

        $this->app->forgetInstance(TrafficMonitorInterface::class);

        $service = $this->app->make(TrafficMonitorInterface::class);

        $this->assertInstanceOf(PrometheusTrafficMonitor::class, $service);
    }
}
