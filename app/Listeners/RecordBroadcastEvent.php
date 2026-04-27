<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\SystemEvent;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Events\Dispatcher;

class RecordBroadcastEvent
{
    private const LEVEL_MAP = [
        'UserConnected' => 'info',
        'DeviceDiscovered' => 'info',
        'SwitchSyncCompleted' => 'info',
        'InternetAccessChanged' => 'info',
        'RateLimitChanged' => 'info',
        'DnsFilterChanged' => 'info',
        'PortStateChanged' => 'info',
        'DhcpPoolThresholdReached' => 'warning',
        'BandwidthAnomalyDetected' => 'warning',
        'SwitchUnreachable' => 'critical',
        'UserBlocked' => 'critical',
    ];

    public function subscribe(Dispatcher $events): void
    {
        $events->listen('*', [self::class, 'handleWildcard']);
    }

    /**
     * @param  list<mixed>  $payload
     */
    public function handleWildcard(string $eventName, array $payload): void
    {
        $event = $payload[0] ?? null;

        if (! $event instanceof ShouldBroadcast) {
            return;
        }

        $this->handleBroadcastEvent($event);
    }

    public function handleBroadcastEvent(ShouldBroadcast $event): void
    {
        try {
            $type = class_basename($event);
            $data = method_exists($event, 'broadcastWith') ? $event->broadcastWith() : [];

            SystemEvent::create([
                'type' => $type,
                'level' => self::LEVEL_MAP[$type] ?? 'info',
                'message' => $this->formatMessage($type, $data),
                'data' => $data ?: null,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function formatMessage(string $type, array $data): string
    {
        return match ($type) {
            'UserConnected' => sprintf('%s connected from %s', $data['user_name'] ?? 'Unknown', $data['ip_address'] ?? 'unknown'),
            'DeviceDiscovered' => $this->formatDeviceDiscovered($data),
            'PortStateChanged' => sprintf('Port %s changed to %s', $data['port_name'] ?? 'unknown', $data['new_status'] ?? 'unknown'),
            'SwitchSyncCompleted' => $this->formatSwitchSyncCompleted($data),
            'DhcpPoolThresholdReached' => sprintf('DHCP pool %s reached %s%% utilization', $data['pool'] ?? 'unknown', (int) ($data['usage'] ?? 0)),
            'InternetAccessChanged' => sprintf('Internet access %s for %s', ($data['enabled'] ?? false) ? 'enabled' : 'disabled', $data['ip_address'] ?? 'unknown'),
            'RateLimitChanged' => sprintf('Rate limit changed for %s from %s to %s', $data['ip_address'] ?? 'unknown', $data['old_limit'] ?? '?', $data['new_limit'] ?? '?'),
            'UserBlocked' => sprintf('%s blocked on %s: %s', $data['user_name'] ?? 'Unknown', $data['ip_address'] ?? 'unknown', $data['reason'] ?? 'no reason'),
            'DnsFilterChanged' => sprintf('DNS filter %s for %s', ($data['enabled'] ?? false) ? 'enabled' : 'disabled', $data['ip_address'] ?? 'unknown'),
            'SwitchUnreachable' => sprintf('Switch %s unreachable after %s failures', $data['hostname'] ?? 'unknown', $data['failure_count'] ?? '?'),
            'BandwidthAnomalyDetected' => $this->formatBandwidthAnomaly($data),
            default => sprintf('%s event received', $type),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function formatDeviceDiscovered(array $data): string
    {
        $msg = sprintf('New device %s discovered', $data['mac_address'] ?? 'unknown');
        if (! empty($data['ip_address'])) {
            $msg .= sprintf(' on %s', $data['ip_address']);
        }

        return $msg;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function formatSwitchSyncCompleted(array $data): string
    {
        $msg = sprintf('Switch %s sync completed (%s ports updated)', $data['hostname'] ?? 'unknown', $data['ports_updated'] ?? 0);
        $errorCount = is_array($data['errors'] ?? null) ? count($data['errors']) : 0;
        if ($errorCount > 0) {
            $msg .= sprintf(' - %d errors', $errorCount);
        }

        return $msg;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function formatBandwidthAnomaly(array $data): string
    {
        $msg = 'Bandwidth anomaly detected';
        if (! empty($data['ip_address'])) {
            $msg .= sprintf(' on %s', $data['ip_address']);
        }

        return $msg;
    }
}
