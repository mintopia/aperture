<?php

declare(strict_types=1);

namespace Tests\Feature\Middleware;

use App\Http\Middleware\TrustProxies;
use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Log;
use ReflectionClass;
use Tests\TestCase;

class TrustProxiesTest extends TestCase
{
    public function test_proxies_defaults_to_wildcard_when_env_not_set(): void
    {
        $this->withoutEnvironmentVariable('TRUSTED_PROXY_IPS');

        $middleware = new TrustProxies;

        $reflection = new ReflectionClass($middleware);
        $property = $reflection->getProperty('proxies');
        $property->setAccessible(true);

        $this->assertSame('*', $property->getValue($middleware));
    }

    public function test_proxies_set_to_wildcard_when_env_is_wildcard(): void
    {
        $middleware = $this->createMiddlewareWithEnv('*');

        $reflection = new ReflectionClass($middleware);
        $property = $reflection->getProperty('proxies');
        $property->setAccessible(true);

        $this->assertSame('*', $property->getValue($middleware));
    }

    public function test_proxies_set_to_array_when_env_has_specific_ips(): void
    {
        $middleware = $this->createMiddlewareWithEnv('192.168.1.1,10.0.0.1');

        $reflection = new ReflectionClass($middleware);
        $property = $reflection->getProperty('proxies');
        $property->setAccessible(true);

        $proxies = $property->getValue($middleware);

        $this->assertIsArray($proxies);
        $this->assertContains('192.168.1.1', $proxies);
        $this->assertContains('10.0.0.1', $proxies);
    }

    public function test_proxies_trims_whitespace_from_ip_list(): void
    {
        $middleware = $this->createMiddlewareWithEnv(' 192.168.1.1 , 10.0.0.1 ');

        $reflection = new ReflectionClass($middleware);
        $property = $reflection->getProperty('proxies');
        $property->setAccessible(true);

        $proxies = $property->getValue($middleware);

        $this->assertIsArray($proxies);
        $this->assertContains('192.168.1.1', $proxies);
        $this->assertContains('10.0.0.1', $proxies);
    }

    public function test_warning_is_logged_in_non_local_non_testing_environment_when_proxies_is_wildcard(): void
    {
        Log::spy();

        $this->app['env'] = 'production';

        putenv('TRUSTED_PROXY_IPS=*');
        $_ENV['TRUSTED_PROXY_IPS'] = '*';
        $_SERVER['TRUSTED_PROXY_IPS'] = '*';

        $provider = new AppServiceProvider($this->app);
        $provider->boot();

        Log::shouldHaveReceived('warning')
            ->once()
            ->with("TRUSTED_PROXY_IPS is set to '*' — all X-Forwarded-For headers are trusted. Configure specific proxy IPs for production.");

        $this->app['env'] = 'testing';
        putenv('TRUSTED_PROXY_IPS');
        unset($_ENV['TRUSTED_PROXY_IPS'], $_SERVER['TRUSTED_PROXY_IPS']);
    }

    public function test_warning_is_not_logged_in_testing_environment(): void
    {
        Log::spy();

        $this->app['env'] = 'testing';

        putenv('TRUSTED_PROXY_IPS=*');
        $_ENV['TRUSTED_PROXY_IPS'] = '*';
        $_SERVER['TRUSTED_PROXY_IPS'] = '*';

        $provider = new AppServiceProvider($this->app);
        $provider->boot();

        Log::shouldNotHaveReceived('warning');

        putenv('TRUSTED_PROXY_IPS');
        unset($_ENV['TRUSTED_PROXY_IPS'], $_SERVER['TRUSTED_PROXY_IPS']);
    }

    public function test_warning_is_not_logged_in_local_environment(): void
    {
        Log::spy();

        $this->app['env'] = 'local';

        putenv('TRUSTED_PROXY_IPS=*');
        $_ENV['TRUSTED_PROXY_IPS'] = '*';
        $_SERVER['TRUSTED_PROXY_IPS'] = '*';

        $provider = new AppServiceProvider($this->app);
        $provider->boot();

        Log::shouldNotHaveReceived('warning');

        $this->app['env'] = 'testing';
        putenv('TRUSTED_PROXY_IPS');
        unset($_ENV['TRUSTED_PROXY_IPS'], $_SERVER['TRUSTED_PROXY_IPS']);
    }

    public function test_warning_is_not_logged_when_specific_ips_configured(): void
    {
        Log::spy();

        $this->app['env'] = 'production';

        putenv('TRUSTED_PROXY_IPS=192.168.1.1');
        $_ENV['TRUSTED_PROXY_IPS'] = '192.168.1.1';
        $_SERVER['TRUSTED_PROXY_IPS'] = '192.168.1.1';

        $provider = new AppServiceProvider($this->app);
        $provider->boot();

        Log::shouldNotHaveReceived('warning');

        $this->app['env'] = 'testing';
        putenv('TRUSTED_PROXY_IPS');
        unset($_ENV['TRUSTED_PROXY_IPS'], $_SERVER['TRUSTED_PROXY_IPS']);
    }

    /**
     * @param  non-empty-string  $envValue
     */
    private function createMiddlewareWithEnv(string $envValue): TrustProxies
    {
        putenv('TRUSTED_PROXY_IPS='.$envValue);
        $_ENV['TRUSTED_PROXY_IPS'] = $envValue;
        $_SERVER['TRUSTED_PROXY_IPS'] = $envValue;

        $middleware = new TrustProxies;

        putenv('TRUSTED_PROXY_IPS');
        unset($_ENV['TRUSTED_PROXY_IPS'], $_SERVER['TRUSTED_PROXY_IPS']);

        return $middleware;
    }

    private function withoutEnvironmentVariable(string $name): void
    {
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
    }
}
