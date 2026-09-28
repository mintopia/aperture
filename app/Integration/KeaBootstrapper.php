<?php

declare(strict_types=1);

namespace App\Integration;

use App\Enums\Capability;
use App\Enums\Integration;
use App\Models\CapabilityAssignment;
use App\Models\IntegrationConfig;
use App\Services\Interfaces\DhcpInterface;
use App\Services\Interfaces\IpMacResolverInterface;
use App\Services\Kea\KeaClient;
use App\Services\Kea\KeaDhcpService;
use App\Services\Kea\KeaIpMacResolver;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

final class KeaBootstrapper implements IntegrationBootstrapper
{
    public function register(Application $app): void
    {
        $app->extend(DhcpInterface::class, function (DhcpInterface $service, Application $app): DhcpInterface {
            if ($this->isActive(Capability::Dhcp->value)) {
                return $this->buildDhcpService() ?? $service;
            }

            return $service;
        });

        $app->extend(IpMacResolverInterface::class, function (IpMacResolverInterface $service, Application $app): IpMacResolverInterface {
            if ($this->isActive(Capability::IpMac->value)) {
                return new KeaIpMacResolver;
            }

            return $service;
        });
    }

    private function buildDhcpService(): ?DhcpInterface
    {
        $config = $this->getIntegrationDbConfig();

        $ipv4Client = $this->buildClient($config, 'v4', 'dhcp4');
        $ipv6Client = $this->buildClient($config, 'v6', 'dhcp6');

        if (! $ipv4Client instanceof KeaClient && ! $ipv6Client instanceof KeaClient) {
            return null;
        }

        return new KeaDhcpService($ipv4Client, $ipv6Client);
    }

    private function isActive(string $capability): bool
    {
        try {
            return CapabilityAssignment::isActiveProvider(Integration::Kea->value, $capability);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function buildClient(array $config, string $suffix, string $service): ?KeaClient
    {
        $endpoint = $config['endpoint_'.$suffix] ?? null;

        if (! is_string($endpoint) || $endpoint === '') {
            return null;
        }

        $username = $config['username_'.$suffix] ?? null;
        $password = $config['password_'.$suffix] ?? null;

        return new KeaClient(
            endpoint: $endpoint,
            username: is_string($username) && $username !== '' ? $username : null,
            password: is_string($password) && $password !== '' ? $password : null,
            verifySsl: (bool) ($config['verify_ssl'] ?? true),
            service: $service,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function getIntegrationDbConfig(): array
    {
        try {
            return IntegrationConfig::getAll(Integration::Kea->value);
        } catch (Throwable) {
            return [];
        }
    }
}
