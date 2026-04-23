<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SwitchPortActionJob;
use App\Jobs\SyncSwitchPortsJob;
use App\Models\SwitchConfig;
use App\Models\SwitchPortMac;
use App\Services\Interfaces\MetricsProviderInterface;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class SwitchPortController extends Controller
{
    public function __construct(
        protected MetricsProviderInterface $metrics,
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
            'macs' => $this->resolveConnectedDevices($macs),
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
     * Resolve connected devices using DB relationships.
     *
     * @param  Collection<int, SwitchPortMac>  $macs
     * @return array<int, array{mac_address: string, vlan: int|null, last_seen_at: string|null, mac_id: int|null, resolved_ips: array<int, array{id: int|null, ip: string, hostname: string|null, user: array{id: int, nickname: string}|null}>}>
     */
    private function resolveConnectedDevices(Collection $macs): array
    {
        $macs->load([
            'macAddressRecord.ipAddresses',
            'macAddressRecord.user',
            'macAddressRecord.dhcpLeases',
        ]);

        $result = [];

        foreach ($macs as $mac) {
            $macRecord = $mac->macAddressRecord;

            $resolvedIps = [];
            if ($macRecord !== null) {
                $ips = $macRecord->ipAddresses()
                    ->orderByPivot('last_seen_at', 'desc')
                    ->get();

                foreach ($ips as $ip) {
                    $latestUser = $ip->users()->with('user')->latest('last_seen_at')->first();

                    $resolvedIps[] = [
                        'id' => $ip->id,
                        'ip' => $ip->address,
                        'hostname' => $macRecord->currentHostname(),
                        'user' => $latestUser?->user ? [
                            'id' => $latestUser->user->id,
                            'nickname' => $latestUser->user->nickname,
                        ] : null,
                    ];
                }
            }

            $result[] = [
                'mac_address' => $mac->mac_address,
                'mac_id' => $macRecord?->id,
                'vlan' => $mac->vlan,
                'last_seen_at' => $this->toIso8601String($mac->last_seen_at),
                'resolved_ips' => $resolvedIps,
            ];
        }

        return $result;
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

    public function refresh(SwitchConfig $switchConfig, string $portId): RedirectResponse
    {
        SyncSwitchPortsJob::dispatch($switchConfig);

        return back()->with('success', 'Port refresh has been queued.');
    }

    public function shutdown(SwitchConfig $switchConfig, string $portId): RedirectResponse
    {
        SwitchPortActionJob::dispatch($switchConfig, $portId, 'shutdown');

        return back()->with('success', 'Port shutdown has been queued.');
    }

    public function enable(SwitchConfig $switchConfig, string $portId): RedirectResponse
    {
        SwitchPortActionJob::dispatch($switchConfig, $portId, 'enable');

        return back()->with('success', 'Port enable has been queued.');
    }
}
