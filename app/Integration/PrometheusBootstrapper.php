<?php

declare(strict_types=1);

namespace App\Integration;

use App\Enums\Capability;
use App\Enums\Integration;
use App\Models\CapabilityAssignment;
use App\Models\IntegrationConfig;
use App\Services\Interfaces\IpBandwidthInterface;
use App\Services\Interfaces\PortBandwidthInterface;
use App\Services\Interfaces\PortErrorsInterface;
use App\Services\Null\NullIpBandwidth;
use App\Services\Null\NullPortBandwidth;
use App\Services\Null\NullPortErrors;
use App\Services\Prometheus\PrometheusIpBandwidth;
use App\Services\Prometheus\PrometheusPortBandwidth;
use App\Services\Prometheus\PrometheusPortErrors;
use App\Services\Prometheus\PrometheusService;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

final class PrometheusBootstrapper implements IntegrationBootstrapper
{
    public function register(Application $app): void
    {
        // ip-bandwidth
        $app->bind(function (Application $app): IpBandwidthInterface {
            if ($this->isActive(Capability::IpBandwidth->value)) {
                $config = $this->getIntegrationDbConfig();

                return new PrometheusIpBandwidth(
                    $app->make(PrometheusService::class),
                    (string) ($config['bandwidth_rcvd_metric'] ?? 'ntopng_host_bytes_rcvd'),
                    (string) ($config['bandwidth_sent_metric'] ?? 'ntopng_host_bytes_sent'),
                    (string) ($config['bandwidth_ip_label'] ?? 'ip'),
                );
            }

            return new NullIpBandwidth;
        });

        // port-bandwidth
        $app->bind(function (Application $app): PortBandwidthInterface {
            if ($this->isActive(Capability::PortBandwidth->value)) {
                return new PrometheusPortBandwidth(
                    $app->make(PrometheusService::class),
                );
            }

            return new NullPortBandwidth;
        });

        // port-errors
        $app->bind(function (Application $app): PortErrorsInterface {
            if ($this->isActive(Capability::PortErrors->value)) {
                return new PrometheusPortErrors(
                    $app->make(PrometheusService::class),
                );
            }

            return new NullPortErrors;
        });
    }

    private function isActive(string $capability): bool
    {
        try {
            return CapabilityAssignment::isActiveProvider(Integration::Prometheus->value, $capability);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function getIntegrationDbConfig(): array
    {
        try {
            return IntegrationConfig::getAll(Integration::Prometheus->value);
        } catch (Throwable) {
            return [];
        }
    }
}
