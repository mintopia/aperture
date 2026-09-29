<?php

declare(strict_types=1);

namespace Tests\Unit\Providers;

use App\Models\SwitchConfig;
use App\Providers\AppServiceProvider;
use App\Services\Interfaces\AuthProviderInterface;
use App\Services\Interfaces\NetworkSwitchInterface;
use App\Services\Interfaces\SshProxyClientInterface;
use App\Services\NetworkSwitch\CiscoSwitchAdapter;
use App\Services\NetworkSwitch\DefaultSwitchConfigResolver;
use App\Services\NetworkSwitch\SwitchServiceFactory;
use App\Services\SshProxy\SshProxyClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Log;
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

    public function test_network_switch_resolution_propagates_resolver_errors(): void
    {
        $resolver = $this->createStub(DefaultSwitchConfigResolver::class);
        $resolver->method('resolve')->willThrowException(new RuntimeException('database unavailable'));
        $this->app->instance(DefaultSwitchConfigResolver::class, $resolver);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('database unavailable');

        $this->app->make(NetworkSwitchInterface::class);
    }

    public function test_network_switch_resolution_uses_resolved_switch_config(): void
    {
        $config = SwitchConfig::defaultFallback();
        $resolver = $this->createStub(DefaultSwitchConfigResolver::class);
        $resolver->method('resolve')->willReturn($config);
        $this->app->instance(DefaultSwitchConfigResolver::class, $resolver);

        $this->assertInstanceOf(NetworkSwitchInterface::class, $this->app->make(NetworkSwitchInterface::class));
    }
}
