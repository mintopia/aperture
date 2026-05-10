<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BandwidthRequest;
use App\Http\Requests\Admin\StoreUserParameterRequest;
use App\Http\Requests\Admin\UpdateUserParameterRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Requests\Admin\UserBlockRequest;
use App\Http\Requests\Admin\UserIndexRequest;
use App\Http\Requests\Admin\UserInternetRequest;
use App\Http\Requests\Admin\UserLimitRequest;
use App\Http\Resources\BandwidthResource;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Models\UserParameter;
use App\Services\Interfaces\IpBandwidthInterface;
use App\Services\UserShowDataService;
use App\Services\ValueObjects\IpBandwidthResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function __construct(
        protected UserShowDataService $showDataService,
    ) {}

    public function index(UserIndexRequest $request): Response
    {
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
        $data = $this->showDataService->assemble($user);

        return Inertia::render('Admin/Users/Show', [
            ...$data,
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
            'availableRoles' => Role::all(['id', 'code', 'name']),
            'breadcrumbs' => [
                ['label' => 'Admin', 'href' => route('admin.home')],
                ['label' => 'Users', 'href' => route('admin.users.index')],
                ['label' => $user->nickname, 'href' => route('admin.users.show', $user)],
                ['label' => 'Edit'],
            ],
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $validated = $request->validated();

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
            $oldRoles = $user->roles->pluck('code')->sort()->values()->all();
            $roleIds = Role::whereIn('code', $validated['roles'] ?? [])->pluck('id');
            $user->roles()->sync($roleIds);
            $newRoles = ($validated['roles'] ?? []);
            sort($newRoles);

            if ($oldRoles !== $newRoles) {
                AuditLog::record(
                    action: 'user.role_changed',
                    subject: $user,
                    process: 'admin',
                    metadata: [
                        'ip' => $request->getClientIp(),
                        'roles' => $newRoles,
                    ],
                );
            }
        }

        return redirect()->route('admin.users.show', $user)->with('success', 'User updated successfully.');
    }

    public function block(UserBlockRequest $request, User $user): RedirectResponse
    {
        $user->internet_blocked = $request->boolean('block');
        $user->save();

        AuditLog::record(
            action: 'user.block_toggled',
            subject: $user,
            process: 'admin',
            metadata: ['blocked' => $user->internet_blocked],
        );

        if ($user->internet_blocked) {
            $message = 'The user will be blocked from accessing the Internet from new IPs';
        } else {
            $message = 'The user will be unblocked from accessing the Internet from new IPs';
        }

        return response()->redirectToRoute('admin.users.show', ['user' => $user->id])->with('success', $message);
    }

    public function internet(UserInternetRequest $request, User $user): RedirectResponse
    {
        $enable = $request->boolean('enable');

        $user->ips()
            ->with('ip')
            ->get()
            ->each(function ($userIp) use ($enable): void {
                $userIp->ip->internet_enabled = $enable;
                $userIp->ip->save();

                AuditLog::record(
                    action: 'ip.internet_toggled',
                    subject: $userIp->ip,
                    process: 'admin',
                    metadata: ['enabled' => $userIp->ip->internet_enabled],
                );
            });

        AuditLog::record(
            action: 'user.internet_toggled',
            subject: $user,
            process: 'admin',
            metadata: ['ip' => $request->getClientIp(), 'enabled' => $enable],
        );

        $message = $enable
            ? 'Internet has been enabled for all user IPs'
            : 'Internet has been disabled for all user IPs';

        return response()->redirectToRoute('admin.users.show', ['user' => $user->id])->with('success', $message);
    }

    public function limit(UserLimitRequest $request, User $user): RedirectResponse
    {
        $limit = $request->boolean('limit');

        $user->ips()
            ->with('ip')
            ->get()
            ->each(function ($userIp) use ($limit): void {
                $userIp->ip->rate_limit_enabled = $limit;
                $userIp->ip->save();

                AuditLog::record(
                    action: 'ip.rate_limit_toggled',
                    subject: $userIp->ip,
                    process: 'admin',
                    metadata: ['enabled' => $userIp->ip->rate_limit_enabled],
                );
            });

        $message = $limit
            ? 'Rate limiting has been enabled for all user IPs'
            : 'Rate limiting has been removed for all user IPs';

        return response()->redirectToRoute('admin.users.show', ['user' => $user->id])->with('success', $message);
    }

    public function bandwidth(BandwidthRequest $request, User $user, IpBandwidthInterface $ipBandwidth): JsonResponse
    {
        $range = $request->validated()['range'] ?? '24h';

        $ipAddresses = $user->ips()->with('ip')->get()
            ->map(fn ($userIp) => $userIp->ip->address)
            ->filter()
            ->values()
            ->all();

        if (empty($ipAddresses)) {
            $bandwidth = new IpBandwidthResult(
                received: 0,
                sent: 0,
                timestamps: [],
                download: [],
                upload: [],
            );
        } else {
            $bandwidth = $ipBandwidth->getIpBandwidth($ipAddresses, $range);
        }

        return BandwidthResource::make($bandwidth)->response();
    }

    public function storeParameter(StoreUserParameterRequest $request, User $user): RedirectResponse
    {
        $validated = $request->validated();

        $user->parameters()->create([
            'key' => $validated['key'],
            'value' => $validated['value'],
        ]);

        return response()->redirectToRoute('admin.users.show', ['user' => $user->id])->with('success', 'Parameter created successfully.');
    }

    public function updateParameter(UpdateUserParameterRequest $request, User $user, UserParameter $parameter): RedirectResponse
    {
        $validated = $request->validated();

        $parameter->update([
            'key' => $validated['key'],
            'value' => $validated['value'],
        ]);

        return response()->redirectToRoute('admin.users.show', ['user' => $user->id])->with('success', 'Parameter updated successfully.');
    }

    public function destroyParameter(User $user, UserParameter $parameter): RedirectResponse
    {
        $parameter->delete();

        return response()->redirectToRoute('admin.users.show', ['user' => $user->id])->with('success', 'Parameter deleted successfully.');
    }
}
