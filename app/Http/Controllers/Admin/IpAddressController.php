<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\IpAddressStoreRequest;
use App\Models\AuditLog;
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Services\Interfaces\IpBandwidthInterface;
use App\Services\IpAddressShowDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IpAddressController extends Controller
{
    public function __construct(
        protected IpAddressShowDataService $showDataService,
    ) {}

    public function index(Request $request): Response
    {
        $filters = (object) [
            'perPage' => $request->input('perPage', 20),
            'address' => $request->input('address', ''),
            'nickname' => $request->input('nickname', ''),
        ];
        $query = IpAddress::query()->with(['users.user']);

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

        if (in_array($request->input('direction'), ['asc', 'desc'])) {
            $direction = $request->input('direction');
        }

        $filters->direction = $direction;

        $ips = $query->with('macAddresses')->orderBy($order, $direction)->paginate($filters->perPage)->appends((array) $filters);
        $ips->through(function (IpAddress $ip): IpAddress {
            $currentMac = $ip->macAddresses
                ->sortByDesc(fn (MacAddress $mac) => $mac->pivot->last_seen_at)
                ->first();
            $ip->setAttribute('mac', $currentMac?->mac_address);

            return $ip;
        });

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
        $data = $this->showDataService->assemble($ip);

        return Inertia::render('Admin/Ips/Show', [
            ...$data,
            'breadcrumbs' => [
                ['label' => 'Admin', 'href' => route('admin.home')],
                ['label' => 'IP Addresses', 'href' => route('admin.ips.index')],
                ['label' => $ip->address],
            ],
        ]);
    }

    public function limit(Request $request, IpAddress $ip): RedirectResponse
    {
        $request->validate(['limit' => 'required|boolean']);

        $ip->rate_limit_enabled = $request->boolean('limit');
        $ip->save();

        AuditLog::record(
            action: 'ip.rate_limit_toggled',
            subject: $ip,
            process: 'admin',
            metadata: ['enabled' => $ip->rate_limit_enabled],
        );

        $message = $ip->rate_limit_enabled ? 'The IP will be rate limited' : 'The rate limit will be removed for this IP';

        return response()->redirectToRoute('admin.ips.show', ['ip' => $ip])->with('success', $message);
    }

    public function internet(Request $request, IpAddress $ip): RedirectResponse
    {
        $request->validate(['allow' => 'required|boolean']);

        $ip->internet_enabled = $request->boolean('allow');
        $ip->save();

        AuditLog::record(
            action: 'ip.internet_toggled',
            subject: $ip,
            process: 'admin',
            metadata: ['enabled' => $ip->internet_enabled],
        );

        $message = $ip->internet_enabled ? 'Internet will be enabled for this IP' : 'Internet will be disabled for this IP';

        return response()->redirectToRoute('admin.ips.show', ['ip' => $ip])->with('success', $message);
    }

    public function dnsFilter(Request $request, IpAddress $ip): RedirectResponse
    {
        $request->validate(['filter' => 'required|boolean']);

        $ip->dns_filtering_enabled = $request->boolean('filter');
        $ip->save();

        AuditLog::record(
            action: 'ip.dns_filter_toggled',
            subject: $ip,
            process: 'admin',
            metadata: ['enabled' => $ip->dns_filtering_enabled],
        );

        $message = $ip->dns_filtering_enabled ? 'DNS filtering will be enabled for this IP' : 'DNS filtering will be disabled for this IP';

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

    public function bandwidth(Request $request, IpAddress $ip, IpBandwidthInterface $ipBandwidth): JsonResponse
    {
        $validated = $request->validate([
            'range' => 'nullable|string|in:1h,24h,4d',
        ]);

        $range = $validated['range'] ?? '24h';

        $bandwidth = $ipBandwidth->getIpBandwidth($ip->address, $range);

        return response()->json([
            'timestamps' => $bandwidth->timestamps,
            'download' => $bandwidth->download,
            'upload' => $bandwidth->upload,
            'totalReceived' => $bandwidth->received,
            'totalSent' => $bandwidth->sent,
        ]);
    }
}
