<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Interfaces\NetworkSwitchInterface;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PortController extends Controller
{
    public function index(NetworkSwitchInterface $switch): Response
    {
        $ports = $switch->getAllPorts();

        return Inertia::render('Admin/Ports/Index', [
            'ports' => $ports,
        ]);
    }

    public function show(string $portId, NetworkSwitchInterface $switch): Response
    {
        $status = $switch->getPortStatus($portId);
        $statistics = $switch->getPortStatistics($portId);

        return Inertia::render('Admin/Ports/Show', [
            'port' => $status,
            'statistics' => $statistics,
            'portId' => $portId,
        ]);
    }

    public function shutdown(string $portId, NetworkSwitchInterface $switch): RedirectResponse
    {
        $switch->shutdownPort($portId);

        return back()->with('success', 'Port has been shut down.');
    }

    public function enable(string $portId, NetworkSwitchInterface $switch): RedirectResponse
    {
        $switch->enablePort($portId);

        return back()->with('success', 'Port has been enabled.');
    }
}
