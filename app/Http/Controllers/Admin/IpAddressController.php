<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\IpAddressStoreRequest;
use App\Jobs\ShutInterface;
use App\Jobs\IpAddressAction;
use App\Models\IpAddress;
use App\Services\CiscoService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class IpAddressController extends Controller
{
    public function index(Request $request)
    {
        $filters = (object) [
            'perPage' => $request->input('perPage', 20),
            'address' => $request->input('address', ''),
            'nickname' => $request->input('nickname', ''),
        ];
        $query = IpAddress::query()->with('users.user');

        if ($filters->address) {
            $query = $query->where('address', $filters->address);
        }
        if ($filters->nickname) {
            $query = $query->whereHas('users.user', function($query) use ($filters) {
                $query->where('nickname', 'LIKE', "%{$filters->nickname}%");
            });
        }

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

    public function create()
    {
        return view('admin.ips.create');
    }

    public function store(IpAddressStoreRequest $request)
    {
        $ip = new IpAddress();
        $ip->address = $request->input('address');
        $ip->comment = $request->input('comment');
        $ip->last_seen_at = Carbon::now();
        $ip->save();
        if ($request->input('allow')) {
            $ip->allow(true);
        }
        if ($request->input('limit')) {
            $ip->limit(true);
        }
        return response()->redirectToRoute('admin.ips.show', ['ip' => $ip->id])->with('successMessage', 'The IP address has been added');
    }
}
