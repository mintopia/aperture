<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\SwitchConfig;
use App\Services\Interfaces\DhcpInterface;
use App\Services\Interfaces\MacAddressResolverInterface;
use App\Services\Interfaces\NetworkInventoryInterface;
use App\Services\Interfaces\NetworkSwitchInterface;
use App\Services\Interfaces\SshProxyClientInterface;
use App\Services\MacAddressResolver;
use App\Services\NetworkSwitch\SwitchServiceFactory;
use App\Services\SshProxy\SshProxyClient;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use RuntimeException;
use Throwable;

class NetworkServiceProvider extends ServiceProvider
{
    /**
     * Register network infrastructure bindings.
     */
    public function register(): void
    {
        $this->app->singleton(function (Application $app): SshProxyClientInterface {
            $host = (string) config('aperture.ssh_proxy.host');
            $isIpAddress = filter_var($host, FILTER_VALIDATE_IP) !== false;
            $isBracketedIpv6 = preg_match('/^\[(.+)]$/', $host, $matches) === 1
                && filter_var($matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
            $isHostname = filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;

            if (
                $host === ''
                ||
                str_contains($host, '://')
                || str_contains($host, '/')
                || str_contains($host, '?')
                || str_contains($host, '#')
                || str_contains($host, '@')
                || preg_match('/\s/', $host) === 1
                || (! $isIpAddress && ! $isBracketedIpv6 && ! $isHostname)
            ) {
                throw new RuntimeException('Invalid SSH proxy host configuration [aperture.ssh_proxy.host]. Use a plain hostname or IP without scheme/path.');
            }

            $urlHost = $host;
            if ($isIpAddress && str_contains($host, ':') && ! str_starts_with($host, '[')) {
                $urlHost = sprintf('[%s]', $host);
            }

            return new SshProxyClient(
                sprintf('http://%s:%d', $urlHost, config('aperture.ssh_proxy.port')),
                (string) config('aperture.ssh_proxy.api_key'),
                timeout: (int) config('aperture.ssh_proxy.request_timeout', 60),
                connectTimeout: (int) config('aperture.ssh_proxy.connect_timeout', 5),
            );
        });

        $this->app->singleton(function (Application $app): SwitchServiceFactory {
            return new SwitchServiceFactory(
                proxyClient: config('aperture.ssh_proxy.enabled', false) ? $app->make(SshProxyClientInterface::class) : null,
                proxyEnabled: (bool) config('aperture.ssh_proxy.enabled', false),
            );
        });

        $this->app->singleton(function (Application $app): NetworkSwitchInterface {
            return $app->make(SwitchServiceFactory::class)->make($this->getDefaultSwitchConfig());
        });

        $this->app->singleton(function (Application $app): MacAddressResolverInterface {
            return new MacAddressResolver(
                $app->make(DhcpInterface::class),
                $app->make(NetworkInventoryInterface::class),
            );
        });
    }

    protected function getDefaultSwitchConfig(): SwitchConfig
    {
        try {
            $switchConfig = SwitchConfig::query()->where('enabled', true)->orderBy('id')->first();
            if ($switchConfig instanceof SwitchConfig) {
                return $switchConfig;
            }
        } catch (Throwable) {
            // DB not available — use config fallback
        }

        return SwitchConfig::defaultFallback();
    }
}
