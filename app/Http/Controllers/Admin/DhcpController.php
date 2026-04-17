<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Interfaces\DhcpInterface;
use App\Services\ValueObjects\DhcpRange;
use Inertia\Inertia;
use Inertia\Response;

class DhcpController extends Controller
{
    public function index(DhcpInterface $dhcp): Response
    {
        return Inertia::render('Admin/Dhcp/Index', [
            'ranges' => $dhcp->getRanges()->map(fn (DhcpRange $range): array => [
                'name' => $range->interface ?: ($range->description ?: 'Default'),
                'ip_version' => $range->type === 'ipv4' ? 'IPv4' : 'IPv6',
                'network' => $range->subnet ?: $range->prefix,
                'start' => $range->rangeFrom,
                'end' => $range->rangeTo,
                'used' => $range->usedAddresses ?? 0,
                'total' => $range->totalAddresses ?? 0,
                'percentage' => $range->utilisation !== null ? round($range->utilisation * 100, 1) : 0,
            ]),
            'breadcrumbs' => [
                ['label' => 'Admin', 'href' => route('admin.home')],
                ['label' => 'DHCP'],
            ],
        ]);
    }

    public function leases(DhcpInterface $dhcp): Response
    {
        return Inertia::render('Admin/Dhcp/Leases', [
            'leases' => $dhcp->getLeases()->map(fn ($lease): array => [
                'ip' => $lease->ip,
                'mac' => $lease->mac,
                'hostname' => $lease->hostname,
                'expires' => $lease->expires,
            ])->values()->all(),
            'ranges' => $dhcp->getRanges()->map(fn (DhcpRange $range): array => [
                'name' => $range->interface ?: ($range->description ?: 'Default'),
                'network' => $range->subnet ?: $range->prefix,
                'start' => $range->rangeFrom,
                'end' => $range->rangeTo,
            ]),
            'breadcrumbs' => [
                ['label' => 'Admin', 'href' => route('admin.home')],
                ['label' => 'DHCP', 'href' => route('admin.dhcp.index')],
                ['label' => 'Leases'],
            ],
        ]);
    }
}
