<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BandwidthRequest;
use App\Http\Resources\BandwidthResource;
use App\Jobs\ResetAperture;
use App\Models\AuditLog;
use App\Models\CapabilityAssignment;
use App\Models\DhcpRangeRecord;
use App\Models\IpAddress;
use App\Models\User;
use App\Services\AuditLog\AuditLogDescriptionGenerator;
use App\Services\Interfaces\IpBandwidthInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Dashboard', [
            'totalUsers' => User::count(),
            'onlineUsers' => User::whereHas('ips', fn ($q) => $q->whereHas('ip', fn ($q2) => $q2->where('internet_enabled', true)))->count(), // @phpstan-ignore argument.templateType
            'activeIps' => IpAddress::where('internet_enabled', true)->count(),
            'blockedUsers' => User::where('internet_blocked', true)->count(),
            'dhcpPools' => Inertia::defer(fn (): array => $this->getDhcpPools()),
            'recentUsers' => Inertia::defer(fn (): LengthAwarePaginator => $this->getRecentUsers()),
            'recentEvents' => Inertia::defer(fn (): array => AuditLog::query()
                ->with(['subject', 'actor', 'related'])
                ->orderByDesc('created_at')
                ->limit(10)
                ->get()
                ->map(fn (AuditLog $log): array => [
                    'id' => $log->id,
                    'action' => $log->action,
                    'description' => AuditLogDescriptionGenerator::generate($log),
                    'severity' => $log->severity,
                    'created_at' => $log->created_at->toIso8601String(),
                ])
                ->all()),
            'breadcrumbs' => [
                ['label' => 'Admin', 'href' => route('admin.home')],
                ['label' => 'Dashboard'],
            ],
        ]);
    }

    public function bandwidth(BandwidthRequest $request, IpBandwidthInterface $ipBandwidth): JsonResponse
    {
        $range = $request->validated()['range'] ?? '24h';

        $bandwidth = $ipBandwidth->getTotalBandwidth($range);

        return BandwidthResource::make($bandwidth)->response();
    }

    public function reset(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'string'],
        ]);

        /** @var User $user */
        $user = $request->user();

        if ($user->password === null) {
            return back()->withErrors(['password' => 'Password required for destructive operations.']);
        }

        if (! Hash::check($request->string('password')->value(), $user->password)) {
            return back()->withErrors(['password' => 'The provided password is incorrect.']);
        }

        AuditLog::record(
            action: 'portal.reset',
            subject: $user,
            actor: $user,
            process: 'admin',
            metadata: ['ip' => $request->getClientIp()],
        );

        ResetAperture::dispatch();

        return redirect()->route('admin.home')->with('success', 'Portal reset initiated.');
    }

    /** @return list<array{name: string, network: string|null, used: int, total: string, utilisation: float}> */
    private function getDhcpPools(): array
    {
        $integration = CapabilityAssignment::activeIntegration('dhcp');

        return array_values(DhcpRangeRecord::where('integration', $integration)
            ->get()
            ->map(fn (DhcpRangeRecord $range): array => [
                'name' => $range->description ?? $range->interface,
                'network' => $range->subnet ?: $range->prefix,
                'used' => $range->used_addresses !== null ? (int) $range->used_addresses : 0,
                // Totals can exceed PHP_INT_MAX (IPv6 /64 → 2^64), so they stay
                // exact decimal numeric strings end-to-end.
                'total' => $range->total_addresses ?? '0',
                'utilisation' => $range->utilisation !== null ? (float) $range->utilisation : 0.0,
            ])
            ->all());
    }

    /** @return LengthAwarePaginator<int, User> */
    private function getRecentUsers(): LengthAwarePaginator
    {
        return User::query()
            ->select('users.*')
            ->selectRaw('(SELECT COUNT(*) FROM user_ip_addresses WHERE user_ip_addresses.user_id = users.id) as ips_count')
            ->selectRaw('(SELECT MAX(user_ip_addresses.last_seen_at) FROM user_ip_addresses WHERE user_ip_addresses.user_id = users.id) as last_seen')
            ->orderByDesc('users.weekly_bandwidth')
            ->orderByDesc('last_seen')
            ->paginate(25);
    }
}
