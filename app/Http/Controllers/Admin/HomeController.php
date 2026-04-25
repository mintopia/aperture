<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ResetAperture;
use App\Models\IpAddress;
use App\Models\User;
use App\Services\Interfaces\DhcpInterface;
use App\Services\Interfaces\TrafficMonitorInterface;
use App\Services\ValueObjects\DhcpRange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function index(DhcpInterface $dhcp): Response
    {
        return Inertia::render('Admin/Dashboard', [
            'totalUsers' => User::count(),
            'onlineUsers' => User::whereHas('ips', fn ($q) => $q->whereHas('ip', fn ($q2) => $q2->where('internet_enabled', true)))->count(), // @phpstan-ignore argument.templateType
            'activeIps' => IpAddress::where('internet_enabled', true)->count(),
            'blockedUsers' => User::where('internet_blocked', true)->count(),
            'dhcpPools' => Inertia::defer(fn (): array => $this->getDhcpPools($dhcp)),
            'recentUsers' => Inertia::defer(fn (): LengthAwarePaginator => $this->getRecentUsers()),
            'breadcrumbs' => [
                ['label' => 'Admin', 'href' => route('admin.home')],
                ['label' => 'Dashboard'],
            ],
        ]);
    }

    public function bandwidth(Request $request, TrafficMonitorInterface $trafficMonitor): JsonResponse
    {
        $validated = $request->validate([
            'range' => 'nullable|string|in:1h,24h,4d',
        ]);

        $range = $validated['range'] ?? '24h';

        $bandwidth = $trafficMonitor->getTotalBandwidth($range);

        return response()->json([
            'timestamps' => $bandwidth->timestamps,
            'download' => $bandwidth->download,
            'upload' => $bandwidth->upload,
            'totalReceived' => $bandwidth->received,
            'totalSent' => $bandwidth->sent,
        ]);
    }

    public function reset(): RedirectResponse
    {
        ResetAperture::dispatch();

        return redirect()->route('admin.home')->with('success', 'Portal reset initiated.');
    }

    /** @return list<array{name: string, network: string|null, used: int, total: int, utilisation: float}> */
    private function getDhcpPools(DhcpInterface $dhcp): array
    {
        /** @var list<array{name: string, network: string|null, used: int, total: int, utilisation: float}> $pools */
        $pools = array_values($dhcp->getRanges()->map(fn (DhcpRange $range): array => [
            'name' => $range->description ?? $range->interface,
            'network' => $range->subnet ?: $range->prefix,
            'used' => $range->usedAddresses ?? 0,
            'total' => $range->totalAddresses ?? 0,
            'utilisation' => $range->utilisation ?? 0.0,
        ])->all());

        return $pools;
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
