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
        $client = $this->buildClient();

        return $client instanceof KeaClient ? new KeaDhcpService($client) : null;
    }

    private function isActive(string $capability): bool
    {
        try {
            return CapabilityAssignment::isActiveProvider(Integration::Kea->value, $capability);
        } catch (Throwable) {
            return false;
        }
    }

    private function buildClient(): ?KeaClient
    {
        $config = $this->getIntegrationDbConfig();
        $endpoint = $config['endpoint_v4'] ?? null;

        if (! is_string($endpoint) || $endpoint === '') {
            return null;
        }

        $username = $config['username_v4'] ?? null;
        $password = $config['password_v4'] ?? null;

        return new KeaClient(
            endpoint: $endpoint,
            username: is_string($username) && $username !== '' ? $username : null,
            password: is_string($password) && $password !== '' ? $password : null,
            verifySsl: (bool) ($config['verify_ssl'] ?? true),
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
