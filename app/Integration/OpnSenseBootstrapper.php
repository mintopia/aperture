<?php

declare(strict_types=1);

namespace App\Integration;

use App\Enums\Capability;
use App\Enums\Integration;
use App\Models\CapabilityAssignment;
use App\Models\IntegrationConfig;
use App\Services\Interfaces\CaptivePortalInterface;
use App\Services\Interfaces\DhcpInterface;
use App\Services\Interfaces\RateLimitingInterface;
use App\Services\Null\NullCaptivePortal;
use App\Services\Null\NullDhcpService;
use App\Services\Null\NullRateLimiter;
use App\Services\OpnSense\OpnSenseCaptivePortal;
use App\Services\OpnSense\OpnSenseClient;
use App\Services\OpnSense\OpnSenseDhcpService;
use App\Services\OpnSense\OpnSenseRateLimiter;
use GuzzleHttp\Client;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

final class OpnSenseBootstrapper implements IntegrationBootstrapper
{
    public function register(Application $app): void
    {
        // captive-portal
        $app->bind(function (Application $app): CaptivePortalInterface {
            if ($this->isActive(Capability::CaptivePortal->value)) {
                return new OpnSenseCaptivePortal(
                    $app->make(OpnSenseClient::class),
                    (int) IntegrationConfig::getValue(Integration::OpnSense->value, 'zone_id', '0'),
                );
            }

            return new NullCaptivePortal;
        });

        // rate-limiting
        $app->bind(function (Application $app): RateLimitingInterface {
            if ($this->isActive(Capability::RateLimiting->value)) {
                return new OpnSenseRateLimiter(
                    $app->make(OpnSenseClient::class),
                    (string) IntegrationConfig::getValue(Integration::OpnSense->value, 'ratelimit_up_uuid', ''),
                    (string) IntegrationConfig::getValue(Integration::OpnSense->value, 'ratelimit_down_uuid', ''),
                );
            }

            return new NullRateLimiter;
        });

        // dhcp
        $app->bind(function (Application $app): DhcpInterface {
            if ($this->isActive(Capability::Dhcp->value)) {
                return $this->buildDhcpService($app);
            }

            return new NullDhcpService;
        });
    }

    private function isActive(string $capability): bool
    {
        try {
            return CapabilityAssignment::isActiveProvider(Integration::OpnSense->value, $capability);
        } catch (Throwable) {
            return false;
        }
    }

    private function buildDhcpService(Application $app): OpnSenseDhcpService
    {
        $opnsenseConfig = $this->getIntegrationDbConfig();
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
                'ipv6_ranges' => '/api/dhcpv6/leases/search_lease',
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

        $client = new Client([
            'verify' => (bool) ($opnsenseConfig['verify_ssl'] ?? true),
            'base_uri' => $opnsenseConfig['endpoint'] ?? '',
            'auth' => [
                $opnsenseConfig['key'] ?? '',
                $opnsenseConfig['secret'] ?? '',
            ],
        ]);

        return new OpnSenseDhcpService(
            $client,
            (int) ($opnsenseConfig['pool_size'] ?? 254),
            $paths['leases'],
            $paths['ipv4_ranges'],
            $paths['ipv6_ranges'],
            $leaseFieldMap,
            $rangeFieldMap,
            $dhcpServer === 'kea',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function getIntegrationDbConfig(): array
    {
        try {
            return IntegrationConfig::getAll(Integration::OpnSense->value);
        } catch (Throwable) {
            return [];
        }
    }
}
