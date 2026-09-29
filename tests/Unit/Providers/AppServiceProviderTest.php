<?php

declare(strict_types=1);

namespace Tests\Unit\Providers;

use App\Providers\AppServiceProvider;
use App\Services\Interfaces\AuthProviderInterface;
use App\Services\Interfaces\NetworkSwitchInterface;
use App\Services\Interfaces\SshProxyClientInterface;
use App\Services\NetworkSwitch\CiscoSwitchAdapter;
use App\Services\NetworkSwitch\SwitchServiceFactory;
use App\Services\SshProxy\SshProxyClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Log;
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

    public function test_critical_log_emitted_when_debug_enabled_in_production(): void
    {
        Log::spy();

        $this->app['env'] = 'production';
        config(['app.debug' => true]);

        $provider = new AppServiceProvider($this->app);
        $provider->boot();

        Log::shouldHaveReceived('critical')
            ->once()
            ->with('APP_DEBUG is enabled in production. Disable it to prevent information disclosure.');

        $this->app['env'] = 'testing';
        config(['app.debug' => false]);
    }

    public function test_no_critical_log_when_debug_disabled_in_production(): void
    {
        Log::spy();

        $this->app['env'] = 'production';
        config(['app.debug' => false]);

        $provider = new AppServiceProvider($this->app);
        $provider->boot();

        Log::shouldNotHaveReceived('critical');

        $this->app['env'] = 'testing';
    }

    public function test_no_critical_log_when_debug_enabled_in_non_production(): void
    {
        Log::spy();

        $this->app['env'] = 'local';
        config(['app.debug' => true]);

        $provider = new AppServiceProvider($this->app);
        $provider->boot();

        Log::shouldNotHaveReceived('critical');

        $this->app['env'] = 'testing';
        config(['app.debug' => false]);
    }

    public function test_network_switch_resolution_propagates_non_missing_table_errors(): void
    {
        $originalDefault = config('database.default');
        config([
            'database.connections.test_invalid' => [
                'driver' => 'sqlite',
                'database' => '/nonexistent/path/that/does/not/exist.sqlite',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
            'database.default' => 'test_invalid',
        ]);

        $this->app->forgetInstance(NetworkSwitchInterface::class);

        $this->expectException(QueryException::class);

        try {
            $this->app->make(NetworkSwitchInterface::class);
        } finally {
            config(['database.default' => $originalDefault]);
            $this->app->forgetInstance(NetworkSwitchInterface::class);
        }
    }
}
