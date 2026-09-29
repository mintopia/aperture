<?php

declare(strict_types=1);

namespace App\Integration;

use App\Enums\Capability;
use App\Enums\Integration;
use App\Services\Prometheus\PrometheusIpBandwidth;
use App\Services\Prometheus\PrometheusPortBandwidth;
use App\Services\Prometheus\PrometheusPortErrors;
use App\Services\Prometheus\PrometheusService;
use Illuminate\Contracts\Foundation\Application;

final class PrometheusBootstrapper implements IntegrationBootstrapper
{
    public function integration(): Integration
    {
        return Integration::Prometheus;
    }

    public function providers(): array
    {
        return [
            Capability::IpBandwidth->value => function (Application $app): PrometheusIpBandwidth {
                $config = InstallGuard::config(Integration::Prometheus->value);

                return new PrometheusIpBandwidth(
                    $app->make(PrometheusService::class),
                    (string) ($config['bandwidth_rcvd_metric'] ?? 'ntopng_host_bytes_rcvd'),
                    (string) ($config['bandwidth_sent_metric'] ?? 'ntopng_host_bytes_sent'),
                    (string) ($config['bandwidth_ip_label'] ?? 'ip'),
                );
            },
            Capability::PortBandwidth->value => fn (Application $app): PrometheusPortBandwidth => new PrometheusPortBandwidth(
                $app->make(PrometheusService::class),
            ),
            Capability::PortErrors->value => fn (Application $app): PrometheusPortErrors => new PrometheusPortErrors(
                $app->make(PrometheusService::class),
            ),
        ];
    }
}
