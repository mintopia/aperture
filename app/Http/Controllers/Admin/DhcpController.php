<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CapabilityAssignment;
use App\Models\DhcpLease;
use App\Models\DhcpRangeRecord;
use App\Models\DhcpSyncState;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class DhcpController extends Controller
{
    public function index(): Response
    {
        $integration = $this->activeIntegration();

        $ranges = DhcpRangeRecord::where('integration', $integration)
            ->get()
            ->map(fn (DhcpRangeRecord $range): array => [
                'name' => $range->interface ?: ($range->description ?: 'Default'),
                'ip_version' => $range->type === 'ipv4' ? 'IPv4' : 'IPv6',
                'network' => $range->subnet ?: $range->prefix,
                'start' => $range->range_from,
                'end' => $range->range_to,
                'used' => (int) ($range->used_addresses ?? 0),
                'total' => (int) ($range->total_addresses ?? 0),
                'percentage' => $range->utilisation !== null ? round((float) $range->utilisation * 100, 1) : 0,
            ]);

        $syncState = DhcpSyncState::where('integration', $integration)
            ->where('dataset', 'ranges')
            ->first();

        return Inertia::render('Admin/Dhcp/Index', [
            'ranges' => $ranges,
            'lastSyncedAt' => $syncState?->last_success_at?->toIso8601String(),
            'breadcrumbs' => [
                ['label' => 'Admin', 'href' => route('admin.home')],
                ['label' => 'DHCP'],
            ],
        ]);
    }

    public function leases(): Response
    {
        $integration = $this->activeIntegration();

        $leases = DhcpLease::where('integration', $integration)
            ->with(['ipAddress', 'macAddress'])
            ->get()
            ->map(fn (DhcpLease $lease): array => [
                'ip' => $lease->ipAddress->address ?? '',
                'mac' => $lease->macAddress->mac_address ?? '',
                'hostname' => $lease->hostname ?? '',
                'expires' => $lease->expires_at?->toIso8601String() ?? '',
            ])
            ->values()
            ->all();

        $ranges = DhcpRangeRecord::where('integration', $integration)
            ->get()
            ->map(fn (DhcpRangeRecord $range): array => [
                'network' => $range->subnet ?: $range->prefix,
                'start' => $range->range_from,
                'end' => $range->range_to,
            ])
            ->values()
            ->all();

        return Inertia::render('Admin/Dhcp/Leases', [
            'leases' => $leases,
            'ranges' => $ranges,
            'breadcrumbs' => [
                ['label' => 'Admin', 'href' => route('admin.home')],
                ['label' => 'DHCP', 'href' => route('admin.dhcp.index')],
                ['label' => 'Leases'],
            ],
        ]);
    }

    private function activeIntegration(): ?string
    {
        try {
            $assignment = CapabilityAssignment::where('capability', 'dhcp')->first();

            return $assignment?->integration;
        } catch (Throwable) {
            return null;
        }
    }
}
