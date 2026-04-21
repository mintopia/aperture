<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\IpAddress;
use App\Models\SwitchConfig;
use App\Models\SwitchPortMac;
use App\Services\Interfaces\MacAddressResolverInterface;
use App\Services\Interfaces\MetricsProviderInterface;
use App\Services\NetworkSwitch\SwitchServiceFactory;
use DateTimeInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class SwitchPortController extends Controller
{
    public function __construct(
        protected SwitchServiceFactory $factory,
        protected MetricsProviderInterface $metrics,
        protected MacAddressResolverInterface $macResolver,
    ) {}

    public function show(SwitchConfig $switchConfig, string $portId): Response
    {
        $port = $switchConfig->switchPorts()
            ->with(['switchPortMacs', 'switchPortConfig'])
            ->where('port_name', $portId)
            ->first();

        if (! $port) {
            abort(404, 'Port not found. The switch may need to be synced first.');
        }

        $macs = $port->switchPortMacs;
        $portConfig = $port->switchPortConfig;
        $portData = [
            'id' => $port->id,
            'interface' => $port->port_name,
            'description' => $port->switch_description,
            'status' => $port->status,
            'admin_status' => $port->admin_status,
            'speed' => $port->speed,
            'vlan' => $port->access_vlan,
            'poe' => $port->poe_status,
            'duplex' => $port->duplex,
            'switchport_mode' => $port->switchport_mode,
            'config_text' => $portConfig?->config_text,
            'interface_output' => $portConfig?->interface_output,
            'last_synced_at' => $this->toIso8601String($port->last_synced_at),
        ];
        $bandwidth = ['in' => [], 'out' => [], 'in_bytes' => 0, 'out_bytes' => 0];
        $errors = ['input' => 0, 'output' => 0, 'crc' => 0, 'collisions' => 0, 'in_series' => [], 'out_series' => []];

        if ($this->metrics->isAvailable()) {
            $end = now()->timestamp;
            $start = now()->subHours(24)->timestamp;

            try {
                $bw = $this->metrics->getPortBandwidth(
                    $switchConfig->hostname,
                    $portId,
                    (float) $start,
                    (float) $end,
                );
                $bandwidth['in'] = $bw['in'];
                $bandwidth['out'] = $bw['out'];
                $bandwidth['in_bytes'] = $this->sumSeries($bw['in']);
                $bandwidth['out_bytes'] = $this->sumSeries($bw['out']);

                $err = $this->metrics->getPortErrors(
                    $switchConfig->hostname,
                    $portId,
                    (float) $start,
                    (float) $end,
                );
                $errors['in_series'] = $err['in'];
                $errors['out_series'] = $err['out'];
                $errors['input'] = $this->sumSeriesValues($err['in']);
                $errors['output'] = $this->sumSeriesValues($err['out']);
            } catch (Throwable $throwable) {
                Log::warning('Failed to fetch port metrics', [
                    'switch' => $switchConfig->id,
                    'port' => $portId,
                    'error' => $throwable->getMessage(),
                ]);
            }
        }

        $allPortNames = $switchConfig->switchPorts()->orderBy('port_name')->pluck('port_name')->all();
        $currentIndex = array_search($portId, $allPortNames, true);
        $currentIndex = is_int($currentIndex) ? $currentIndex : null;

        $prevPort = $currentIndex !== null && $currentIndex > 0 ? $allPortNames[$currentIndex - 1] : null;
        $nextPort = $currentIndex !== null && $currentIndex < count($allPortNames) - 1 ? $allPortNames[$currentIndex + 1] : null;

        return Inertia::render('Admin/Switches/Ports/Show', [
            'switchConfig' => $switchConfig->toPublicArray(),
            'port' => $portData,
            'prevPort' => $prevPort,
            'nextPort' => $nextPort,
            'macs' => $macs->map(function (SwitchPortMac $mac): array {
                $resolvedIps = [];

                try {
                    $resolvedIps = $this->macResolver->resolveMacToIps($mac->mac_address);
                } catch (Throwable) {
                    // DHCP unavailable, continue without IP resolution
                }

                $resolvedIps = array_map(function (array $ipData): array {
                    try {
                        $ipRecord = IpAddress::where('address', $ipData['ip'])->first();
                        $latestUser = $ipRecord?->users()->with('user')->latest('last_seen_at')->first();

                        return [
                            'id' => $ipRecord?->id,
                            'ip' => $ipData['ip'],
                            'hostname' => $ipData['hostname'],
                            'user' => $latestUser?->user ? [
                                'id' => $latestUser->user->id,
                                'nickname' => $latestUser->user->nickname,
                            ] : null,
                        ];
                    } catch (Throwable) {
                        return [
                            'id' => null,
                            'ip' => $ipData['ip'],
                            'hostname' => $ipData['hostname'],
                            'user' => null,
                        ];
                    }
                }, $resolvedIps);

                return [
                    'mac_address' => $mac->mac_address,
                    'vlan' => $mac->vlan,
                    'last_seen_at' => $this->toIso8601String($mac->last_seen_at),
                    'resolved_ips' => $resolvedIps,
                ];
            }),
            'bandwidth' => $bandwidth,
            'errors' => $errors,
            'metricsAvailable' => $this->metrics->isAvailable(),
            'breadcrumbs' => [
                ['label' => 'Admin', 'href' => route('admin.home')],
                ['label' => 'Switches', 'href' => route('admin.switches.index')],
                ['label' => $switchConfig->name, 'href' => route('admin.switches.show', $switchConfig)],
                ['label' => $portId],
            ],
        ]);
    }

    /**
     * Sum rate values to approximate total bytes transferred.
     * Each data point represents rate in bits/sec, multiply by step interval then /8 for bytes.
     *
     * @param  array<int, array{timestamp: float, value: float}>  $series
     */
    private function sumSeries(array $series): int
    {
        if (count($series) < 2) {
            return 0;
        }

        $total = 0.0;
        $counter = count($series);
        for ($i = 1; $i < $counter; $i++) {
            $interval = $series[$i]['timestamp'] - $series[$i - 1]['timestamp'];
            $avgRate = ($series[$i]['value'] + $series[$i - 1]['value']) / 2;
            $total += $avgRate * $interval / 8;
        }

        return (int) round($total);
    }

    /**
     * Sum all values in a time series.
     *
     * @param  array<int, array{timestamp: float, value: float}>  $series
     */
    private function sumSeriesValues(array $series): int
    {
        return (int) round(array_sum(array_column($series, 'value')));
    }

    private function toIso8601String(mixed $value): ?string
    {
        if (! $value instanceof DateTimeInterface) {
            return null;
        }

        return $value->format(DateTimeInterface::ATOM);
    }

    public function shutdown(SwitchConfig $switchConfig, string $portId): RedirectResponse
    {
        try {
            $adapter = $this->factory->make($switchConfig);
            $adapter->shutdownPort($portId);

            return back()->with('success', 'Port has been shut down.');
        } catch (Throwable $throwable) {
            Log::warning('Port shutdown failed', ['switch' => $switchConfig->id, 'port' => $portId, 'error' => $throwable->getMessage()]);

            return back()->with('error', 'Failed to shut down port. Please try again.');
        }
    }

    public function enable(SwitchConfig $switchConfig, string $portId): RedirectResponse
    {
        try {
            $adapter = $this->factory->make($switchConfig);
            $adapter->enablePort($portId);

            return back()->with('success', 'Port has been enabled.');
        } catch (Throwable $throwable) {
            Log::warning('Port enable failed', ['switch' => $switchConfig->id, 'port' => $portId, 'error' => $throwable->getMessage()]);

            return back()->with('error', 'Failed to enable port. Please try again.');
        }
    }

    public function bounce(SwitchConfig $switchConfig, string $portId): RedirectResponse
    {
        try {
            $adapter = $this->factory->make($switchConfig);
            $adapter->shutdownPort($portId);
            $adapter->enablePort($portId);

            return back()->with('success', 'Port has been bounced.');
        } catch (Throwable $throwable) {
            Log::warning('Port bounce failed', ['switch' => $switchConfig->id, 'port' => $portId, 'error' => $throwable->getMessage()]);

            return back()->with('error', 'Failed to bounce port. Please try again.');
        }
    }
}
