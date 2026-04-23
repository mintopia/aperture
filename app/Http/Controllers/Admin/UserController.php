<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\MacAddress;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        $ips = $user->ips()->with('ip')->get();
        $roles = $user->roles()->get();

        $downloaded = $ips->sum('ip.received');
        $uploaded = $ips->sum('ip.sent');

        $macAddresses = $user->macAddresses()
            ->get()
            ->map(function (MacAddress $mac) {
                return [
                    'id' => $mac->id,
                    'mac_address' => $mac->mac_address,
                    'hostname' => $mac->currentHostname(),
                    'current_ips' => $mac->ipAddresses()
                        ->orderByPivot('last_seen_at', 'desc')
                        ->take(3)
                        ->get()
                        ->map(fn ($ip) => ['id' => $ip->id, 'address' => $ip->address]),
                    'source' => $mac->source,
                ];
            });

        $auditLogs = AuditLog::where('subject_type', $user->getMorphClass())
            ->where('subject_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn ($log) => [
                'id' => $log->id,
                'action' => $log->action,
                'process' => $log->process,
                'metadata' => $log->metadata,
                'created_at' => $log->created_at?->toIso8601String(),
            ]);

        return Inertia::render('Admin/Users/Show', [
            'user' => $user,
            'roles' => $roles,
            'ips' => $ips,
            'downloaded' => $downloaded,
            'uploaded' => $uploaded,
            'macAddresses' => $macAddresses,
            'auditLogs' => $auditLogs,
            'breadcrumbs' => [
                ['label' => 'Admin', 'href' => route('admin.home')],
                ['label' => 'Users', 'href' => route('admin.users.index')],
                ['label' => $user->nickname],
            ],
        ]);
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
}
