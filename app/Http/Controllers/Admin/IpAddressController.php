<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\IpAddressStoreRequest;
use App\Jobs\IpAddressAction;
use App\Models\IpAddress;
use App\Models\SwitchConfig;
use App\Services\Interfaces\TrafficMonitorInterface;
use App\Services\IpAddressActionService;
use App\Services\NetworkSwitch\SwitchServiceFactory;
use App\Services\ValueObjects\PortDetail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class IpAddressController extends Controller
{
    public function __construct(
        protected SwitchServiceFactory $switchServiceFactory,
        protected IpAddressActionService $ipAddressActionService,
    ) {}

    public function index(Request $request): Response
    {
        $filters = (object) [
            'perPage' => $request->input('perPage', 20),
            'address' => $request->input('address', ''),
            'nickname' => $request->input('nickname', ''),
        ];
        $query = IpAddress::query()->with(['users.user', 'macAddress']);

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
            'internet_enabled',
            'rate_limit_enabled',
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
            'breadcrumbs' => [
                ['label' => 'Admin', 'href' => route('admin.home')],
                ['label' => 'IP Addresses'],
            ],
        ]);
    }

    public function show(IpAddress $ip): Response
    {
        $status = null;
        $config = null;
        $shutdown = false;
        $port = $this->ipAddressActionService->getPortInfo($ip);
        if ($port !== null) {
            $shutdown = $port->adminStatus === 'down';

            try {
                $switch = $this->switchServiceFactory->make($this->resolveSwitchConfig($port));
                $portStatus = $switch->getPortStatus($port->interface);
                $status = sprintf('%s is %s', $portStatus->interface, $portStatus->status);
            } catch (Throwable) {
                $status = 'Unable to connect to switch';
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
            'breadcrumbs' => [
                ['label' => 'Admin', 'href' => route('admin.home')],
                ['label' => 'IP Addresses', 'href' => route('admin.ips.index')],
                ['label' => $ip->address],
            ],
        ]);
    }

    public function port(Request $request, IpAddress $ip): RedirectResponse
    {
        if ($request->input('shutdown') == 1) {
            IpAddressAction::dispatch($ip, 'shutPort');
            $message = 'The network port will be disabled';
        } else {
            IpAddressAction::dispatch($ip, 'unshutPort');
            $message = 'The network port will be enabled';
        }

        return response()->redirectToRoute('admin.ips.show', ['ip' => $ip])->with('success', $message);
    }

    public function limit(Request $request, IpAddress $ip): RedirectResponse
    {
        $ip->rate_limit_enabled = (bool) $request->input('limit');
        $ip->save();

        $message = $ip->rate_limit_enabled ? 'The IP will be rate limited' : 'The rate limit will be removed for this IP';

        return response()->redirectToRoute('admin.ips.show', ['ip' => $ip])->with('success', $message);
    }

    public function internet(Request $request, IpAddress $ip): RedirectResponse
    {
        $ip->internet_enabled = (bool) $request->input('allow');
        $ip->save();

        $message = $ip->internet_enabled ? 'Internet will be enabled for this IP' : 'Internet will be disabled for this IP';

        return response()->redirectToRoute('admin.ips.show', ['ip' => $ip])->with('success', $message);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Ips/Create', [
            'breadcrumbs' => [
                ['label' => 'Admin', 'href' => route('admin.home')],
                ['label' => 'IP Addresses', 'href' => route('admin.ips.index')],
                ['label' => 'Create'],
            ],
        ]);
    }

    public function store(IpAddressStoreRequest $request): RedirectResponse
    {
        $ip = new IpAddress;
        $ip->address = $request->input('address');
        $ip->comment = $request->input('comment');
        $ip->last_seen_at = now();
        $ip->internet_enabled = (bool) $request->input('allow');
        $ip->rate_limit_enabled = (bool) $request->input('limit');
        $ip->save();

        return response()->redirectToRoute('admin.ips.show', ['ip' => $ip])->with('success', 'The IP address has been added');
    }

    public function bandwidth(Request $request, IpAddress $ip, TrafficMonitorInterface $trafficMonitor): JsonResponse
    {
        $validated = $request->validate([
            'range' => 'nullable|string|in:1h,24h,4d',
        ]);

        $range = $validated['range'] ?? '24h';

        $bandwidth = $trafficMonitor->getUserBandwidth($ip->address, $range);

        return response()->json([
            'timestamps' => $bandwidth->timestamps,
            'download' => $bandwidth->download,
            'upload' => $bandwidth->upload,
            'totalReceived' => $bandwidth->received,
            'totalSent' => $bandwidth->sent,
        ]);
    }

    protected function resolveSwitchConfig(PortDetail $port): SwitchConfig
    {
        $switchConfig = SwitchConfig::query()
            ->where('enabled', true)
            ->where('hostname', $port->hostname)
            ->first();

        if ($switchConfig instanceof SwitchConfig) {
            return $switchConfig;
        }

        return SwitchConfig::defaultFallback();
    }
}
