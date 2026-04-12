<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\IpAddressStoreRequest;
use App\Models\IpAddress;
use App\Services\CiscoService;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IpAddressController extends Controller
{
    public function index(Request $request): Response
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
            $query = $query->whereHas('users.user', function ($query) use ($filters): void {
                $query->where('nickname', 'LIKE', sprintf('%%%s%%', $filters->nickname));
            });
        }

        $orderBy = [
            'address',
            'received',
            'sent',
            'last_seen_at',
            'allowed',
            'limited',
        ];
        $order = 'address';
        if (in_array($request->input('order'), $orderBy)) {
            $order = $request->input('order');
        }

        $filters->order = $order;
        $direction = 'asc';
        if ($order === 'received' || $order === 'sent') {
            $direction = 'desc';
        }

        if (in_array($request->input('direction'), ['asc', 'desc'])) {
            $direction = $request->input('direction');
        }

        $filters->direction = $direction;

        $ips = $query->orderBy($order, $direction)->paginate($filters->perPage)->appends((array) $filters);

        return Inertia::render('Admin/Ips/Index', [
            'ips' => $ips,
            'filters' => $filters,
        ]);
    }

    public function show(IpAddress $ip): Response
    {
        $status = null;
        $config = null;
        $shutdown = false;
        $port = $ip->port;
        if ($port !== null) {
            try {
                $cisco = $this->createCiscoService($ip->port->switch);
                $status = $cisco->showInterface($ip->port->interface);
                $config = $cisco->showInterfaceConfig($ip->port->interface);
                $shutdown = str_contains($config, 'shutdown');
            } catch (Exception $ex) {
                $status = 'Unable to connect to switch';
                $config = 'Unable to connect to switch';
            }
        }

        $users = $ip->users()->with('user')->get();

        return Inertia::render('Admin/Ips/Show', [
            'ip' => $ip,
            'port' => $port,
            'status' => $status,
            'config' => $config,
            'shutdown' => $shutdown,
            'users' => $users,
        ]);
    }

    public function port(Request $request, IpAddress $ip): RedirectResponse
    {
        if ($request->input('shutdown') == 1) {
            $ip->shutPort(true);
            $message = 'The network port will be disabled';
        } else {
            $ip->unshutPort(true);
            $message = 'The network port will be enabled';
        }

        return response()->redirectToRoute('admin.ips.show', ['ip' => $ip->id])->with('success', $message);
    }

    public function limit(Request $request, IpAddress $ip): RedirectResponse
    {
        if ($request->input('limit') == 1) {
            $ip->limit(true);
            $message = 'The IP will be rate limited';
        } else {
            $ip->unlimit(true);
            $message = 'The rate limit will be removed for this IP';
        }

        return response()->redirectToRoute('admin.ips.show', ['ip' => $ip->id])->with('success', $message);
    }

    public function internet(Request $request, IpAddress $ip): RedirectResponse
    {
        if ($request->input('allow') == 1) {
            $ip->allow(true);
            $message = 'Internet will be enabled for this IP';
        } else {
            $ip->deny(true);
            $message = 'Internet will be disabled for this IP';
        }

        return response()->redirectToRoute('admin.ips.show', ['ip' => $ip->id])->with('success', $message);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Ips/Create');
    }

    public function store(IpAddressStoreRequest $request): RedirectResponse
    {
        $ip = new IpAddress;
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

        return response()->redirectToRoute('admin.ips.show', ['ip' => $ip->id])->with('success', 'The IP address has been added');
    }

    protected function createCiscoService(string $hostname): CiscoService
    {
        return new CiscoService($hostname);
    }
}
