<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\DhcpLease;
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Models\SwitchConfig;
use App\Models\User;
use App\Models\UserIpAddress;
use App\Services\Interfaces\PortBandwidthInterface;
use App\Services\Interfaces\PortErrorsInterface;
use App\Services\LibreNms\LibreNmsService;
use App\Services\ValueObjects\PortDetail;
use App\Services\ValueObjects\ResolvedPort;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class IpAddressShowDataService
{
    public function __construct(
        protected PortBandwidthInterface $portBandwidth,
        protected PortErrorsInterface $portErrors,
        protected LibreNmsService $libreNms,
    ) {}

    /**
     * Assemble all display data for the IP address show page.
     *
     * @return array{
     *     ip: array<string, mixed>,
     *     port: PortDetail|null,
     *     switchInfo: array{switchId: int, switchName: string, portId: string}|null,
     *     portBandwidth: array{in: array<int, array{timestamp: float, value: float}>, out: array<int, array{timestamp: float, value: float}>, in_bytes: int, out_bytes: int}|null,
     *     portErrors: array{in_series: array<int, array{timestamp: float, value: float}>, out_series: array<int, array{timestamp: float, value: float}>}|null,
     *     metricsAvailable: bool,
     *     users: \Illuminate\Database\Eloquent\Collection<int, UserIpAddress>,
     *     macAddresses: Collection<int, mixed>,
     *     dhcpLeases: Collection<int, mixed>,
     *     auditLogs: Collection<int, mixed>,
     * }
     */
    public function assemble(IpAddress $ip): array
    {
        $switchInfo = null;
        $portBandwidth = ['in' => [], 'out' => [], 'in_bytes' => 0, 'out_bytes' => 0];
        $portErrors = ['in_series' => [], 'out_series' => []];
        $metricsAvailable = $this->portBandwidth->isAvailable();

        $port = $this->resolvePortInfo($ip);
        if ($port instanceof PortDetail) {
            $switchConfig = $this->resolveSwitchConfig($port);
            $switchInfo = [
                'switchId' => $switchConfig->id,
                'switchName' => $switchConfig->hostname,
                'portId' => $port->interface,
            ];

            if ($metricsAvailable) {
                $end = (float) now()->timestamp;
                $start = (float) now()->subHours(24)->timestamp;

                try {
                    $bw = $this->portBandwidth->getPortBandwidth($switchConfig->hostname, $port->interface, $start, $end);
                    $portBandwidth['in'] = $bw->in;
                    $portBandwidth['out'] = $bw->out;
                    $portBandwidth['in_bytes'] = $this->sumSeries($bw->in);
                    $portBandwidth['out_bytes'] = $this->sumSeries($bw->out);

                    $err = $this->portErrors->getPortErrors($switchConfig->hostname, $port->interface, $start, $end);
                    $portErrors['in_series'] = $err->in;
                    $portErrors['out_series'] = $err->out;
                } catch (Throwable $e) {
                    Log::warning('Failed to fetch port metrics for IP show', [
                        'ip' => $ip->address,
                        'switch' => $switchConfig->hostname,
                        'port' => $port->interface,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $users = $ip->users()->with('user')->get();
        $currentMac = $ip->currentMac();

        $macAddresses = $ip->macAddresses()
            ->orderByPivot('last_seen_at', 'desc')
            ->get()
            ->map(function ($mac): array {
                return [
                    'id' => $mac->id,
                    'mac_address' => $mac->mac_address,
                    'source' => $mac->pivot->source,
                    'last_seen_at' => Carbon::parse($mac->pivot->last_seen_at)->toIso8601String(),
                    'user' => $mac->user instanceof User ? ['id' => $mac->user->id, 'nickname' => $mac->user->nickname] : null,
                ];
            });

        $dhcpLeases = $ip->dhcpLeases()
            ->with('macAddress')
            ->latest()
            ->get()
            ->map(fn (DhcpLease $lease): array => [
                'id' => $lease->id,
                'mac_address' => $lease->macAddress instanceof MacAddress ? ['id' => $lease->macAddress->id, 'mac_address' => $lease->macAddress->mac_address] : null,
                'hostname' => $lease->hostname,
                'expires_at' => $lease->expires_at?->toIso8601String(),
                'updated_at' => $lease->updated_at?->toIso8601String(),
            ]);

        $auditLogs = $this->getAuditLogs($ip);

        return [
            'ip' => array_merge($ip->toArray(), [
                'current_mac' => $currentMac instanceof MacAddress ? [
                    'id' => $currentMac->id,
                    'mac_address' => $currentMac->mac_address,
                ] : null,
            ]),
            'port' => $port,
            'switchInfo' => $switchInfo,
            'portBandwidth' => $switchInfo !== null ? $portBandwidth : null,
            'portErrors' => $switchInfo !== null ? $portErrors : null,
            'metricsAvailable' => $metricsAvailable,
            'users' => $users,
            'macAddresses' => $macAddresses,
            'dhcpLeases' => $dhcpLeases,
            'auditLogs' => $auditLogs,
        ];
    }

    /**
     * Sum rate values to approximate total bytes transferred.
     *
     * @param  array<int, array{timestamp: float, value: float}>  $series
     */
    public function sumSeries(array $series): int
    {
        $total = 0;
        $counter = count($series);
        for ($i = 1; $i < $counter; $i++) {
            $dt = $series[$i]['timestamp'] - $series[$i - 1]['timestamp'];
            $avgRate = ($series[$i]['value'] + $series[$i - 1]['value']) / 2;
            $total += $avgRate * $dt / 8;
        }

        return (int) $total;
    }

    public function resolvePortInfo(IpAddress $ip): ?PortDetail
    {
        $dbPort = $this->resolvePortFromDatabase($ip);
        if ($dbPort instanceof PortDetail) {
            return $dbPort;
        }

        try {
            $resolved = $this->libreNms->resolveIpToPort($ip->address);
            if (! $resolved instanceof ResolvedPort) {
                return null;
            }

            return $this->libreNms->getPortDetail($resolved->port);
        } catch (Throwable) {
            return null;
        }
    }

    private function resolvePortFromDatabase(IpAddress $ip): ?PortDetail
    {
        $mac = $ip->currentMac();
        if (! $mac instanceof MacAddress) {
            return null;
        }

        $switchPort = $mac->switchPorts()
            ->orderByPivot('last_seen_at', 'desc')
            ->with('switchConfig')
            ->first();

        if ($switchPort === null) {
            return null;
        }

        return new PortDetail(
            hostname: $switchPort->switchConfig->hostname,
            interface: $switchPort->port_name,
            status: $switchPort->status,
            adminStatus: $switchPort->admin_status,
            speed: $switchPort->speed !== null ? (int) $switchPort->speed : 0,
        );
    }

    public function resolveSwitchConfig(PortDetail $port): SwitchConfig
    {
        $switchConfig = SwitchConfig::query()
            ->where('enabled', true)
            ->where('hostname', $port->hostname)
            ->first();

        if ($switchConfig instanceof SwitchConfig) {
            return $switchConfig;
        }

        return SwitchConfig::defaultFallback();
    }

    /**
     * @return Collection<int, mixed>
     */
    private function getAuditLogs(IpAddress $ip): Collection
    {
        return AuditLog::where('subject_type', $ip->getMorphClass())
            ->where('subject_id', $ip->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn (AuditLog $log): array => [
                'id' => $log->id,
                'action' => $log->action,
                'process' => $log->process,
                'metadata' => $log->metadata,
                'created_at' => $log->created_at->toIso8601String(),
            ]);
    }
}
