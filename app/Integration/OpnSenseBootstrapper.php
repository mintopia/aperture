<?php

declare(strict_types=1);

namespace App\Integration;

use App\Enums\Capability;
use App\Enums\Integration;
use App\Services\OpnSense\OpnSenseCaptivePortal;
use App\Services\OpnSense\OpnSenseClient;
use App\Services\OpnSense\OpnSenseDhcpService;
use App\Services\OpnSense\OpnSenseRateLimiter;
use Illuminate\Contracts\Foundation\Application;

final class OpnSenseBootstrapper implements IntegrationBootstrapper
{
    public function integration(): Integration
    {
        return Integration::OpnSense;
    }

    public function providers(): array
    {
        return [
            Capability::CaptivePortal->value => fn (Application $app): OpnSenseCaptivePortal => new OpnSenseCaptivePortal(
                $app->make(OpnSenseClient::class),
                (int) (InstallGuard::config(Integration::OpnSense->value)['zone_id'] ?? 0),
            ),
            Capability::RateLimiting->value => function (Application $app): OpnSenseRateLimiter {
                $config = InstallGuard::config(Integration::OpnSense->value);

                return new OpnSenseRateLimiter(
                    $app->make(OpnSenseClient::class),
                    (string) ($config['ratelimit_up_uuid'] ?? ''),
                    (string) ($config['ratelimit_down_uuid'] ?? ''),
                );
            },
            Capability::Dhcp->value => fn (): OpnSenseDhcpService => $this->buildDhcpService(),
        ];
    }

    private function buildDhcpService(): OpnSenseDhcpService
    {
        $opnsenseConfig = InstallGuard::config(Integration::OpnSense->value);
        $dhcpServer = (string) ($opnsenseConfig['dhcp_server'] ?? 'isc');
        $paths = match ($dhcpServer) {
            'kea' => [
                'leases' => '/api/kea/leases/search',
                'ipv4_ranges' => '/api/kea/dhcpv4/search_subnet',
                'ipv6_ranges' => '/api/kea/dhcpv6/search_subnet',
            ],
            'dnsmasq' => [
                'leases' => '/api/dnsmasq/leases/search',
                'ipv4_ranges' => '/api/dnsmasq/settings/search_range',
                'ipv6_ranges' => '/api/dnsmasq/settings/search_range',
            ],
            default => [
                'leases' => '/api/dhcpv4/leases/search_lease',
                'ipv4_ranges' => '',
                // The ISC DHCPv6 API only exposes leases, so there is no ranges endpoint to poll.
                'ipv6_ranges' => '',
            ],
        };
        $leaseFieldMap = match ($dhcpServer) {
            'kea' => [
                'ip' => 'address',
                'mac' => 'hwaddr',
                'hostname' => 'hostname',
                'expires' => 'expire',
                'status' => 'state',
            ],
            'dnsmasq' => [
                'ip' => 'address',
                'mac' => 'hwaddr',
                'hostname' => 'hostname',
                'expires' => 'expire',
                'status' => 'status',
            ],
            default => [
                'ip' => 'address',
                'mac' => 'mac',
                'hostname' => 'hostname',
                'expires' => 'ends',
                'status' => 'status',
            ],
        };
        $rangeFieldMap = match ($dhcpServer) {
            'kea' => [
                'interface' => 'interface',
                'subnet' => 'subnet',
                'range_from' => 'range_from',
                'range_to' => 'range_to',
                'gateway' => 'option_data.routers',
                'description' => 'description',
                'prefix' => 'prefix',
                'pools' => 'pools',
            ],
            'dnsmasq' => [
                'interface' => 'interface',
                'subnet' => 'subnet',
                'range_from' => 'start_addr',
                'range_to' => 'end_addr',
                'gateway' => 'gateway',
                'description' => '%set_tag',
                'prefix' => 'prefix_len',
                'subnet_mask' => 'subnet_mask',
            ],
            default => [
                'interface' => 'interface',
                'subnet' => 'subnet',
                'range_from' => 'range_from',
                'range_to' => 'range_to',
                'gateway' => 'gateway',
                'description' => 'description',
                'prefix' => 'prefix',
            ],
        };

        return new OpnSenseDhcpService(
            OpnSenseClient::fromConfig($opnsenseConfig)->request(),
            (int) ($opnsenseConfig['pool_size'] ?? 254),
            $paths['leases'],
            $paths['ipv4_ranges'],
            $paths['ipv6_ranges'],
            $leaseFieldMap,
            $rangeFieldMap,
            $dhcpServer === 'kea',
        );
    }
}
