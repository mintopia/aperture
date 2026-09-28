<?php

declare(strict_types=1);

namespace App\Integration;

use App\Enums\Capability;
use App\Enums\Integration;
use App\Services\Interfaces\DhcpInterface;
use App\Services\Kea\KeaClient;
use App\Services\Kea\KeaDhcpService;
use App\Services\Kea\KeaIpMacResolver;

final class KeaBootstrapper implements IntegrationBootstrapper
{
    public function integration(): Integration
    {
        return Integration::Kea;
    }

    public function providers(): array
    {
        return [
            Capability::Dhcp->value => fn (): ?DhcpInterface => $this->buildDhcpService(),
            Capability::IpMac->value => fn (): KeaIpMacResolver => new KeaIpMacResolver,
        ];
    }

    private function buildDhcpService(): ?DhcpInterface
    {
        $config = InstallGuard::config(Integration::Kea->value);

        $ipv4Client = $this->buildClient($config, 'v4', 'dhcp4');
        $ipv6Client = $this->buildClient($config, 'v6', 'dhcp6');

        if (! $ipv4Client instanceof KeaClient && ! $ipv6Client instanceof KeaClient) {
            return null;
        }

        return new KeaDhcpService($ipv4Client, $ipv6Client);
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
}
