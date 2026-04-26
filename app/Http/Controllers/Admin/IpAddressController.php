<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\IpAddressStoreRequest;
use App\Jobs\IpAddressAction;
use App\Models\AuditLog;
use App\Models\DhcpLease;
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Models\SwitchConfig;
use App\Models\User;
use App\Services\Interfaces\IpBandwidthInterface;
use App\Services\Interfaces\PortBandwidthInterface;
use App\Services\Interfaces\PortErrorsInterface;
use App\Services\IpAddressActionService;
use App\Services\LibreNms\LibreNmsService;
use App\Services\ValueObjects\PortDetail;
use App\Services\ValueObjects\ResolvedPort;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class IpAddressController extends Controller
{
    public function __construct(
        protected IpAddressActionService $ipAddressActionService,
        protected PortBandwidthInterface $portBandwidth,
        protected PortErrorsInterface $portErrors,
        protected LibreNmsService $libreNms,
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
        $switchInfo = null;
        $portBandwidth = ['in' => [], 'out' => [], 'in_bytes' => 0, 'out_bytes' => 0];
        $portErrors = ['in_series' => [], 'out_series' => []];
        $metricsAvailable = $this->portBandwidth->isAvailable();

        $port = $this->resolvePortInfo($ip);
        if ($port instanceof PortDetail) {
            $switchConfig = $this->resolveSwitchConfig($port);
            $switchInfo = [
                'switchId' => $switchConfig->id,
                'switchName' => $switchConfig->hostname,
                'portId' => $port->interface,
            ];

            if ($metricsAvailable) {
                $end = (float) now()->timestamp;
                $start = (float) now()->subHours(24)->timestamp;

                try {
                    $bw = $this->portBandwidth->getPortBandwidth($switchConfig->hostname, $port->interface, $start, $end);
                    $portBandwidth['in'] = $bw->in;
                    $portBandwidth['out'] = $bw->out;
                    $portBandwidth['in_bytes'] = $this->sumSeries($bw->in);
                    $portBandwidth['out_bytes'] = $this->sumSeries($bw->out);

                    $err = $this->portErrors->getPortErrors($switchConfig->hostname, $port->interface, $start, $end);
                    $portErrors['in_series'] = $err->in;
                    $portErrors['out_series'] = $err->out;
                } catch (Throwable $e) {
                    Log::warning('Failed to fetch port metrics for IP show', [
                        'ip' => $ip->address,
                        'switch' => $switchConfig->hostname,
                        'port' => $port->interface,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $users = $ip->users()->with('user')->get();
        $currentMac = $ip->currentMac();

        $macAddresses = $ip->macAddresses()
            ->orderByPivot('last_seen_at', 'desc')
            ->get()
            ->map(function ($mac): array {
                return [
                    'id' => $mac->id,
                    'mac_address' => $mac->mac_address,
                    'source' => $mac->pivot->source,
                    'last_seen_at' => Carbon::parse($mac->pivot->last_seen_at)->toIso8601String(),
                    'user' => $mac->user instanceof User ? ['id' => $mac->user->id, 'nickname' => $mac->user->nickname] : null,
                ];
            });

        $dhcpLeases = $ip->dhcpLeases()
            ->with('macAddress')
            ->latest()
            ->get()
            ->map(fn (DhcpLease $lease): array => [
                'id' => $lease->id,
                'mac_address' => $lease->macAddress instanceof MacAddress ? ['id' => $lease->macAddress->id, 'mac_address' => $lease->macAddress->mac_address] : null,
                'hostname' => $lease->hostname,
                'expires_at' => $lease->expires_at?->toIso8601String(),
                'updated_at' => $lease->updated_at?->toIso8601String(),
            ]);

        $auditLogs = AuditLog::where('subject_type', $ip->getMorphClass())
            ->where('subject_id', $ip->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn (AuditLog $log): array => [
                'id' => $log->id,
                'action' => $log->action,
                'process' => $log->process,
                'metadata' => $log->metadata,
                'created_at' => $log->created_at->toIso8601String(),
            ]);

        return Inertia::render('Admin/Ips/Show', [
            'ip' => array_merge($ip->toArray(), [
                'current_mac' => $currentMac instanceof MacAddress ? [
                    'id' => $currentMac->id,
                    'mac_address' => $currentMac->mac_address,
                ] : null,
            ]),
            'port' => $port,
            'switchInfo' => $switchInfo,
            'portBandwidth' => $switchInfo !== null ? $portBandwidth : null,
            'portErrors' => $switchInfo !== null ? $portErrors : null,
            'metricsAvailable' => $metricsAvailable,
            'users' => $users,
            'macAddresses' => $macAddresses,
            'dhcpLeases' => $dhcpLeases,
            'auditLogs' => $auditLogs,
            'breadcrumbs' => [
                ['label' => 'Admin', 'href' => route('admin.home')],
                ['label' => 'IP Addresses', 'href' => route('admin.ips.index')],
                ['label' => $ip->address],
            ],
        ]);
    }

    public function port(Request $request, IpAddress $ip): RedirectResponse
    {
        $request->validate(['shutdown' => 'required|boolean']);

        if ($request->boolean('shutdown')) {
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

    /**
     * Sum rate values to approximate total bytes transferred.
     *
     * @param  array<int, array{timestamp: float, value: float}>  $series
     */
    private function sumSeries(array $series): int
    {
        $total = 0;
        $counter = count($series);
        for ($i = 1; $i < $counter; $i++) {
            $dt = $series[$i]['timestamp'] - $series[$i - 1]['timestamp'];
            $avgRate = ($series[$i]['value'] + $series[$i - 1]['value']) / 2;
            $total += $avgRate * $dt / 8;
        }

        return (int) $total;
    }

    protected function resolvePortInfo(IpAddress $ip): ?PortDetail
    {
        try {
            $resolved = $this->libreNms->resolveIpToPort($ip->address);
            if (! $resolved instanceof ResolvedPort) {
                return null;
            }

            return $this->libreNms->getPortDetail($resolved->port);
        } catch (Throwable) {
            return null;
        }
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
