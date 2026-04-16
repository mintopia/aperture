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
            'pool' => $dhcp->getPoolStatus(),
            'ranges' => $dhcp->getRanges()->map(fn (DhcpRange $range): array => [
                'interface' => $range->interface,
                'type' => $range->type,
                'subnet' => $range->subnet,
                'range_from' => $range->rangeFrom,
                'range_to' => $range->rangeTo,
                'prefix' => $range->prefix,
                'gateway' => $range->gateway,
                'description' => $range->description,
            ]),
        ]);
    }

    public function leases(DhcpInterface $dhcp): Response
    {
        return Inertia::render('Admin/Dhcp/Leases', [
            'leases' => $dhcp->getLeases(),
        ]);
    }
}
