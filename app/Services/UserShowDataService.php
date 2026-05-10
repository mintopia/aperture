<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\IpAddress;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;

class UserShowDataService
{
    /**
     * Assemble all display data for the user show page.
     *
     * @return array{
     *     user: User,
     *     roles: \Illuminate\Database\Eloquent\Collection<int, Role>,
     *     networkDevices: list<array<string, mixed>>,
     *     allInternetEnabled: bool,
     *     allRateLimited: bool,
     *     ipCount: int,
     *     parameters: Collection<int, mixed>,
     *     auditLogs: Collection<int, mixed>,
     * }
     */
    public function assemble(User $user): array
    {
        $userIps = $user->ips()->with('ip')->get();
        $roles = $user->roles()->get();

        $ipModels = $userIps->map(fn ($userIp) => $userIp->ip)->filter();

        $networkDevices = $this->buildNetworkDevices($user, $ipModels);

        $allInternetEnabled = $ipModels->isNotEmpty() && $ipModels->every(fn (IpAddress $ip): bool => $ip->internet_enabled);
        $allRateLimited = $ipModels->isNotEmpty() && $ipModels->every(fn (IpAddress $ip): bool => $ip->rate_limit_enabled);

        $auditLogs = $this->getAuditLogs($user);

        $parameters = $user->parameters()->orderBy('key')->get()
            ->map(fn ($param): array => [
                'id' => $param->id,
                'key' => $param->key,
                'value' => $param->value,
            ]);

        return [
            'user' => $user,
            'roles' => $roles,
            'networkDevices' => $networkDevices,
            'allInternetEnabled' => $allInternetEnabled,
            'allRateLimited' => $allRateLimited,
            'ipCount' => $ipModels->count(),
            'parameters' => $parameters,
            'auditLogs' => $auditLogs,
        ];
    }

    /**
     * @param  Collection<int, IpAddress>  $ipModels
     * @return list<array{mac_address: string|null, mac_id: int|null, ip_address: string|null, ip_id: int|null, hostname: string|null, switch_name: string|null, switch_id: int|null, port_name: string|null, internet_enabled: bool|null, rate_limit_enabled: bool|null, last_seen_at: string|null}>
     */
    public function buildNetworkDevices(User $user, Collection $ipModels): array
    {
        $macs = $user->macAddresses()
            ->with([
                'switchPorts' => fn ($q) => $q->with('switchConfig')->orderByPivot('last_seen_at', 'desc'),
                'ipAddresses' => fn ($q) => $q->orderByPivot('last_seen_at', 'desc'),
                'dhcpLeases' => fn ($q) => $q->whereNotNull('hostname')->latest(),
            ])
            ->get();

        $ipAddressSet = $ipModels->pluck('address')->flip();
        $devices = [];
        $coveredIps = [];

        foreach ($macs as $mac) {
            // Already eager-loaded — no extra queries
            $macIps = $mac->ipAddresses->sortByDesc(fn ($ip) => $ip->pivot->last_seen_at);

            $switchPort = $mac->switchPorts->first(); // ordered by eager load

            $switchInfo = $switchPort !== null ? [
                'switch_name' => $switchPort->switchConfig->name ?? $switchPort->switchConfig->hostname,
                'switch_id' => $switchPort->switch_config_id,
                'port_name' => $switchPort->port_name,
            ] : ['switch_name' => null, 'switch_id' => null, 'port_name' => null];

            $hostname = $mac->dhcpLeases->first()?->hostname;

            $relevantIps = $macIps->filter(fn (IpAddress $ip) => $ipAddressSet->has($ip->address));

            if ($relevantIps->isEmpty()) {
                $devices[] = array_merge([
                    'mac_address' => $mac->mac_address,
                    'mac_id' => $mac->id,
                    'ip_address' => null,
                    'ip_id' => null,
                    'hostname' => $hostname,
                    'internet_enabled' => null,
                    'rate_limit_enabled' => null,
                    'last_seen_at' => null,
                ], $switchInfo);
            } else {
                foreach ($relevantIps as $ip) {
                    $coveredIps[$ip->address] = true;
                    $devices[] = array_merge([
                        'mac_address' => $mac->mac_address,
                        'mac_id' => $mac->id,
                        'ip_address' => $ip->address,
                        'ip_id' => $ip->id,
                        'hostname' => $hostname,
                        'internet_enabled' => $ip->internet_enabled,
                        'rate_limit_enabled' => $ip->rate_limit_enabled,
                        'last_seen_at' => $ip->pivot->last_seen_at->toIso8601String(),
                    ], $switchInfo);
                }
            }
        }

        foreach ($ipModels as $ip) {
            if (! isset($coveredIps[$ip->address])) {
                $devices[] = [
                    'mac_address' => null,
                    'mac_id' => null,
                    'ip_address' => $ip->address,
                    'ip_id' => $ip->id,
                    'hostname' => null,
                    'switch_name' => null,
                    'switch_id' => null,
                    'port_name' => null,
                    'internet_enabled' => $ip->internet_enabled,
                    'rate_limit_enabled' => $ip->rate_limit_enabled,
                    'last_seen_at' => null,
                ];
            }
        }

        return $devices;
    }

    /**
     * @return Collection<int, mixed>
     */
    private function getAuditLogs(User $user): Collection
    {
        return AuditLog::where('subject_type', $user->getMorphClass())
            ->where('subject_id', $user->id)
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
