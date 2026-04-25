<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\IpAddress;
use App\Models\Role;
use App\Models\User;
use App\Services\Interfaces\TrafficMonitorInterface;
use App\Services\ValueObjects\UserBandwidth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $request->validate([
            'perPage' => 'sometimes|integer|min:1|max:100',
        ]);

        $filters = (object) [
            'perPage' => $request->input('perPage', 20),
            'nickname' => $request->input('nickname', ''),
            'ip' => $request->input('ip', ''),
        ];
        $query = User::query()->with('ips.ip')->with('roles');

        if ($filters->nickname) {
            $query = $query->where('nickname', $filters->nickname);
        }

        if ($filters->ip) {
            $query = $query->whereHas('ips.ip', function ($query) use ($filters) {
                return $query->where('address', $filters->ip);
            });
        }

        $users = $query->paginate($filters->perPage)->appends((array) $filters);

        return Inertia::render('Admin/Users/Index', [
            'users' => $users,
            'filters' => $filters,
            'breadcrumbs' => [
                ['label' => 'Admin', 'href' => route('admin.home')],
                ['label' => 'Users'],
            ],
        ]);
    }

    public function show(User $user): Response
    {
        $userIps = $user->ips()->with('ip')->get();
        $roles = $user->roles()->get();

        $downloaded = $userIps->sum('ip.received');
        $uploaded = $userIps->sum('ip.sent');

        $ipModels = $userIps->map(fn ($userIp) => $userIp->ip)->filter();

        $networkDevices = $this->buildNetworkDevices($user, $ipModels);

        $allInternetEnabled = $ipModels->isNotEmpty() && $ipModels->every(fn (IpAddress $ip): bool => $ip->internet_enabled);
        $allRateLimited = $ipModels->isNotEmpty() && $ipModels->every(fn (IpAddress $ip): bool => $ip->rate_limit_enabled);

        $auditLogs = AuditLog::where('subject_type', $user->getMorphClass())
            ->where('subject_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn ($log): array => [
                'id' => $log->id,
                'action' => $log->action,
                'process' => $log->process,
                'metadata' => $log->metadata,
                'created_at' => $log->created_at->toIso8601String(),
            ]);

        return Inertia::render('Admin/Users/Show', [
            'user' => $user,
            'roles' => $roles,
            'downloaded' => $downloaded,
            'uploaded' => $uploaded,
            'networkDevices' => $networkDevices,
            'allInternetEnabled' => $allInternetEnabled,
            'allRateLimited' => $allRateLimited,
            'ipCount' => $ipModels->count(),
            'auditLogs' => $auditLogs,
            'breadcrumbs' => [
                ['label' => 'Admin', 'href' => route('admin.home')],
                ['label' => 'Users', 'href' => route('admin.users.index')],
                ['label' => $user->nickname],
            ],
        ]);
    }

    /**
     * @param  Collection<int, IpAddress>  $ipModels
     * @return list<array{mac_address: string|null, mac_id: int|null, ip_address: string|null, ip_id: int|null, hostname: string|null, switch_name: string|null, switch_id: int|null, port_name: string|null, internet_enabled: bool|null, rate_limit_enabled: bool|null, last_seen_at: string|null}>
     */
    private function buildNetworkDevices(User $user, Collection $ipModels): array
    {
        $macs = $user->macAddresses()
            ->with(['switchPorts.switchConfig'])
            ->get();

        $ipAddressSet = $ipModels->pluck('address')->flip();
        $devices = [];
        $coveredIps = [];

        foreach ($macs as $mac) {
            $macIps = $mac->ipAddresses()
                ->orderByPivot('last_seen_at', 'desc')
                ->get();

            $switchPort = $mac->switchPorts()
                ->orderByPivot('last_seen_at', 'desc')
                ->with('switchConfig')
                ->first();

            $switchInfo = $switchPort !== null ? [
                'switch_name' => $switchPort->switchConfig->name ?? $switchPort->switchConfig->hostname,
                'switch_id' => $switchPort->switch_config_id,
                'port_name' => $switchPort->port_name,
            ] : ['switch_name' => null, 'switch_id' => null, 'port_name' => null];

            $hostname = $mac->currentHostname();

            $relevantIps = $macIps->filter(fn (IpAddress $ip) => $ipAddressSet->has($ip->address));

            if ($relevantIps->isEmpty()) {
                $devices[] = array_merge([
                    'mac_address' => $mac->mac_address,
                    'mac_id' => $mac->id,
                    'ip_address' => null,
                    'ip_id' => null,
                    'hostname' => $hostname,
                    'internet_enabled' => null,
                    'rate_limit_enabled' => null,
                    'last_seen_at' => null,
                ], $switchInfo);
            } else {
                foreach ($relevantIps as $ip) {
                    $coveredIps[$ip->address] = true;
                    $devices[] = array_merge([
                        'mac_address' => $mac->mac_address,
                        'mac_id' => $mac->id,
                        'ip_address' => $ip->address,
                        'ip_id' => $ip->id,
                        'hostname' => $hostname,
                        'internet_enabled' => $ip->internet_enabled,
                        'rate_limit_enabled' => $ip->rate_limit_enabled,
                        'last_seen_at' => $ip->pivot->last_seen_at?->toIso8601String(),
                    ], $switchInfo);
                }
            }
        }

        foreach ($ipModels as $ip) {
            if (! isset($coveredIps[$ip->address])) {
                $devices[] = [
                    'mac_address' => null,
                    'mac_id' => null,
                    'ip_address' => $ip->address,
                    'ip_id' => $ip->id,
                    'hostname' => null,
                    'switch_name' => null,
                    'switch_id' => null,
                    'port_name' => null,
                    'internet_enabled' => $ip->internet_enabled,
                    'rate_limit_enabled' => $ip->rate_limit_enabled,
                    'last_seen_at' => null,
                ];
            }
        }

        return $devices;
    }

    public function edit(User $user): Response
    {
        return Inertia::render('Admin/Users/Edit', [
            'user' => [
                'id' => $user->id,
                'nickname' => $user->nickname,
                'email' => $user->email,
                'internet_blocked' => $user->internet_blocked,
                'has_password' => $user->password !== null,
                'roles' => $user->roles->pluck('code'),
                'avatar_url' => $user->avatar_url,
            ],
            'availableRoles' => Role::all(['id', 'code', 'name']),
            'breadcrumbs' => [
                ['label' => 'Admin', 'href' => route('admin.home')],
                ['label' => 'Users', 'href' => route('admin.users.index')],
                ['label' => $user->nickname, 'href' => route('admin.users.show', $user)],
                ['label' => 'Edit'],
            ],
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $rules = [
            'nickname' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'roles' => 'sometimes|array',
            'roles.*' => 'string|exists:roles,code',
        ];

        if ($request->filled('password')) {
            $rules['password'] = 'required|string|min:8|confirmed';
        }

        $validated = $request->validate($rules);

        $user->nickname = $validated['nickname'];
        $user->email = $validated['email'];

        if (isset($validated['password'])) {
            $user->password = $validated['password'];
        }

        if ($request->boolean('clear_password')) {
            $user->password = null;
        }

        $user->save();

        if ($request->has('roles')) {
            $roleIds = Role::whereIn('code', $validated['roles'] ?? [])->pluck('id');
            $user->roles()->sync($roleIds);
        }

        return redirect()->route('admin.users.show', $user)->with('success', 'User updated successfully.');
    }

    public function block(Request $request, User $user): RedirectResponse
    {
        $request->validate(['block' => 'required|boolean']);
        $user->internet_blocked = $request->boolean('block');
        $user->save();
        if ($user->internet_blocked) {
            $message = 'The user will be blocked from accessing the Internet from new IPs';
        } else {
            $message = 'The user will be unblocked from accessing the Internet from new IPs';
        }

        return response()->redirectToRoute('admin.users.show', ['user' => $user->id])->with('success', $message);
    }

    public function internet(Request $request, User $user): RedirectResponse
    {
        $request->validate(['enable' => 'required|boolean']);
        $enable = $request->boolean('enable');

        $user->ips()
            ->with('ip')
            ->get()
            ->each(function ($userIp) use ($enable): void {
                if ($userIp->ip instanceof IpAddress) {
                    $userIp->ip->internet_enabled = $enable;
                    $userIp->ip->save();
                }
            });

        $message = $enable
            ? 'Internet has been enabled for all user IPs'
            : 'Internet has been disabled for all user IPs';

        return response()->redirectToRoute('admin.users.show', ['user' => $user->id])->with('success', $message);
    }

    public function limit(Request $request, User $user): RedirectResponse
    {
        $request->validate(['limit' => 'required|boolean']);
        $limit = $request->boolean('limit');

        $user->ips()
            ->with('ip')
            ->get()
            ->each(function ($userIp) use ($limit): void {
                if ($userIp->ip instanceof IpAddress) {
                    $userIp->ip->rate_limit_enabled = $limit;
                    $userIp->ip->save();
                }
            });

        $message = $limit
            ? 'Rate limiting has been enabled for all user IPs'
            : 'Rate limiting has been removed for all user IPs';

        return response()->redirectToRoute('admin.users.show', ['user' => $user->id])->with('success', $message);
    }

    public function bandwidth(Request $request, User $user, TrafficMonitorInterface $trafficMonitor): JsonResponse
    {
        $validated = $request->validate([
            'range' => 'nullable|string|in:1h,24h,4d',
        ]);

        $range = $validated['range'] ?? '24h';

        $ipAddresses = $user->ips()->with('ip')->get()
            ->map(fn ($userIp) => $userIp->ip?->address)
            ->filter()
            ->values()
            ->all();

        if (empty($ipAddresses)) {
            $bandwidth = new UserBandwidth(
                received: 0,
                sent: 0,
                timestamps: [],
                download: [],
                upload: [],
            );
        } else {
            $bandwidth = $trafficMonitor->getUserBandwidth($ipAddresses, $range);
        }

        return response()->json([
            'timestamps' => $bandwidth->timestamps,
            'download' => $bandwidth->download,
            'upload' => $bandwidth->upload,
            'totalReceived' => $bandwidth->received,
            'totalSent' => $bandwidth->sent,
        ]);
    }
}
