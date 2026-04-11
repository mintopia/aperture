<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Interfaces\DhcpInterface;
use Inertia\Inertia;
use Inertia\Response;

class DhcpController extends Controller
{
    public function index(DhcpInterface $dhcp): Response
    {
        return Inertia::render('Admin/Dhcp/Index', [
            'pool' => $dhcp->getPoolStatus(),
        ]);
    }

    public function leases(DhcpInterface $dhcp): Response
    {
        return Inertia::render('Admin/Dhcp/Leases', [
            'leases' => $dhcp->getLeases(),
        ]);
    }
}
