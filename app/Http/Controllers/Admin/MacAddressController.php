<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Models\SwitchPortMac;
use App\Support\SearchHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class MacAddressController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = (object) [
            'perPage' => $request->input('perPage', 20),
            'mac' => (string) $request->input('mac', ''),
            'hostname' => (string) $request->input('hostname', ''),
            'nickname' => (string) $request->input('nickname', ''),
            'ip' => (string) $request->input('ip', ''),
            'order' => 'created_at',
            'direction' => 'desc',
        ];

        $query = MacAddress::query()->with(['user', 'dhcpLeases', 'ipAddresses']);

        if ($filters->mac !== '') {
            $query->where('mac_address', 'LIKE', SearchHelper::toLikePattern($filters->mac));
        }

        if ($filters->hostname !== '') {
            $query->whereHas('dhcpLeases', function ($q) use ($filters): void {
                $q->where('hostname', 'LIKE', SearchHelper::toLikePattern($filters->hostname));
            });
        }

        if ($filters->nickname !== '') {
            $query->whereHas('user', function ($q) use ($filters): void {
                $q->where('nickname', 'LIKE', SearchHelper::toLikePattern($filters->nickname));
            });
        }

        if ($filters->ip !== '') {
            $query->whereHas('ipAddresses', function ($q) use ($filters): void {
                $q->where('address', 'LIKE', SearchHelper::toLikePattern($filters->ip));
            });
        }

        $orderBy = ['mac_address', 'source', 'created_at'];
        if (in_array($request->input('order'), $orderBy)) {
            $filters->order = $request->input('order');
        }

        if (in_array($request->input('direction'), ['asc', 'desc'])) {
            $filters->direction = $request->input('direction');
        }

        $macs = $query->orderBy($filters->order, $filters->direction)
            ->paginate($filters->perPage)
            ->appends((array) $filters);

        $macs->getCollection()->transform(function (MacAddress $mac): array {
            return [
                'id' => $mac->id,
                'mac_address' => $mac->mac_address,
                'hostname' => $mac->currentHostname(),
                'current_ips' => $mac->ipAddresses
                    ->sortByDesc('pivot.last_seen_at')
                    ->take(3)
                    ->map(fn ($ip): array => ['id' => $ip->id, 'address' => $ip->address])
                    ->values(),
                'user' => $mac->user ? ['id' => $mac->user->id, 'nickname' => $mac->user->nickname] : null,
                'source' => $mac->source,
                'created_at' => $mac->created_at?->toIso8601String(),
            ];
        });

        return Inertia::render('Admin/Macs/Index', [
            'macs' => $macs,
            'filters' => $filters,
            'breadcrumbs' => [
                ['label' => 'Admin', 'href' => route('admin.home')],
                ['label' => 'MAC Addresses'],
            ],
        ]);
    }

    public function show(MacAddress $mac): Response
    {
        $ipAddresses = $mac->ipAddresses()
            ->orderByPivot('last_seen_at', 'desc')
            ->get()
            ->map(function (IpAddress $ip): array {
                return [
                    'id' => $ip->id,
                    'address' => $ip->address,
                    'source' => $ip->pivot->source,
                    'last_seen_at' => Carbon::parse($ip->pivot->last_seen_at)->toIso8601String(),
                ];
            });

        $dhcpLeases = $mac->dhcpLeases()
            ->with('ipAddress')
            ->latest()
            ->get()
            ->map(fn ($lease): array => [
                'id' => $lease->id,
                'ip_address' => $lease->ipAddress ? ['id' => $lease->ipAddress->id, 'address' => $lease->ipAddress->address] : null,
                'hostname' => $lease->hostname,
                'expires_at' => $lease->expires_at?->toIso8601String(),
                'created_at' => $lease->created_at?->toIso8601String(),
                'updated_at' => $lease->updated_at?->toIso8601String(),
            ]);

        $switchPorts = SwitchPortMac::where('mac_address_id', $mac->id)
            ->with(['switchPort.switchConfig'])
            ->get()
            ->map(function (SwitchPortMac $portMac): array {
                $switchConfig = $portMac->switchPort->switchConfig ?? null;

                return [
                    'id' => $portMac->switchPort->id,
                    'port_name' => $portMac->switchPort->port_name,
                    'switch_id' => $switchConfig?->id,
                    'switch_name' => $switchConfig !== null ? ($switchConfig->name ?? $switchConfig->hostname) : null,
                    'vlan' => $portMac->vlan,
                    'last_seen_at' => $portMac->last_seen_at !== null ? $portMac->last_seen_at->toIso8601String() : null,
                ];
            });

        $auditLogs = AuditLog::where('subject_type', $mac->getMorphClass())
            ->where('subject_id', $mac->id)
            ->orWhere(function ($q) use ($mac): void {
                $q->where('related_type', $mac->getMorphClass())
                    ->where('related_id', $mac->id);
            })
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->load('actor')
            ->map(fn ($log): array => [
                'id' => $log->id,
                'action' => $log->action,
                'actor' => $log->actor !== null && $log->actor_type !== null ? class_basename($log->actor_type).' '.($log->actor->nickname ?? $log->actor->name ?? '#'.$log->actor_id) : null,
                'process' => $log->process,
                'metadata' => $log->metadata,
                'created_at' => $log->created_at->toIso8601String(),
            ]);

        return Inertia::render('Admin/Macs/Show', [
            'mac' => [
                'id' => $mac->id,
                'mac_address' => $mac->mac_address,
                'hostname' => $mac->currentHostname(),
                'user' => $mac->user ? ['id' => $mac->user->id, 'nickname' => $mac->user->nickname] : null,
                'source' => $mac->source,
                'description' => $mac->description,
                'created_at' => $mac->created_at?->toIso8601String(),
            ],
            'ipAddresses' => $ipAddresses,
            'dhcpLeases' => $dhcpLeases,
            'switchPorts' => $switchPorts,
            'auditLogs' => $auditLogs,
            'breadcrumbs' => [
                ['label' => 'Admin', 'href' => route('admin.home')],
                ['label' => 'MAC Addresses', 'href' => route('admin.macs.index')],
                ['label' => $mac->mac_address],
            ],
        ]);
    }
}
