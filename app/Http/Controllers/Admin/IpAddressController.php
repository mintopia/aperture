<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ShutInterface;
use App\Jobs\IpAddressAction;
use App\Models\IpAddress;
use App\Services\CiscoService;
use Illuminate\Http\Request;

class IpAddressController extends Controller
{
    public function index(Request $request)
    {
        $filters = (object) [
            'perPage' => $request->input('perPage', 20),
        ];
        $query = IpAddress::query()->with('users.user');

        $ips = $query->paginate($filters->perPage)->appends((array) $filters);
        return view('admin.ips.index', [
            'ips' => $ips,
            'filters' => $filters,
        ]);
    }

    public function show(IpAddress $ip)
    {
        $status = null;
        $config = null;
        $shutdown = false;
        $port = $ip->port;
        if ($port !== null) {
            try {
                $cisco = new CiscoService($ip->port->switch);
                $status = $cisco->showInterface($ip->port->interface);
                $config = $cisco->showInterfaceConfig($ip->port->interface);
                $shutdown = str_contains($config, 'shutdown');
            } catch (\Exception $ex) {
                $status = 'Unable to connect to switch';
                $config = 'Unable to connect to switch';
            }
        }
        $users = $ip->users()->with('user')->get();
        return view('admin.ips.show', [
            'ip' => $ip,
            'port' => $port,
            'status' => $status,
            'config' => $config,
            'shutdown' => $shutdown,
            'users' => $users,
        ]);
    }

    public function port(Request $request, IpAddress $ip)
    {
        if ($request->input('shutdown') == 1) {
            $ip->shutPort(true);
            $message = 'The network port will be disabled';
        } else {
            $ip->unshutPort(true);
            $message = 'The network port will be enabled';
        }
        return response()->redirectToRoute('admin.ips.show', ['ip' => $ip->id])->with('successMessage', $message);
    }

    public function limit(Request $request, IpAddress $ip)
    {
        if ($request->input('limit') == 1) {
            $ip->limit(true);
            $message = 'The IP will be rate limited';
        } else {
            $ip->unlimit(true);
            $message = 'The rate limit will be removed for this IP';
        }
        return response()->redirectToRoute('admin.ips.show', ['ip' => $ip->id])->with('successMessage', $message);
    }

    public function internet(Request $request, IpAddress $ip)
    {
        if ($request->input('allow') == 1) {
            $ip->allow(true);
            $message = 'Internet will be enabled for this IP';
        } else {
            $ip->deny(true);
            $message = 'Internet will be disabled for this IP';
        }
        return response()->redirectToRoute('admin.ips.show', ['ip' => $ip->id])->with('successMessage', $message);
    }
}
