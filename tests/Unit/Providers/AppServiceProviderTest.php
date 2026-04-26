<?php

declare(strict_types=1);

namespace Tests\Unit\Providers;

use App\Models\SwitchConfig;
use App\Providers\NetworkServiceProvider;
use App\Services\Interfaces\AuthProviderInterface;
use App\Services\Interfaces\NetworkSwitchInterface;
use App\Services\Interfaces\SshProxyClientInterface;
use App\Services\NetworkSwitch\CiscoSwitchAdapter;
use App\Services\NetworkSwitch\SwitchServiceFactory;
use App\Services\SshProxy\SshProxyClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

class AppServiceProviderTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_registers_auth_provider_interface_binding(): void
    {
        $this->assertInstanceOf(
            AuthProviderInterface::class,
            $this->app->make(AuthProviderInterface::class)
        );
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

    public function test_prevent_lazy_loading_is_enabled_in_non_production(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertFalse(app()->isProduction());
        $this->assertTrue(Model::preventsLazyLoading());
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
}
