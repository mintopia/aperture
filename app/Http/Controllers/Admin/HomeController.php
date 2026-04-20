<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ResetAperture;
use App\Models\IpAddress;
use App\Models\User;
use App\Services\Interfaces\DhcpInterface;
use App\Services\ValueObjects\DhcpRange;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function index(DhcpInterface $dhcp): Response
    {
        return Inertia::render('Admin/Dashboard', [
            'totalUsers' => User::count(),
            'onlineUsers' => User::whereHas('ips', fn ($q) => $q->whereHas('ip', fn ($q2) => $q2->where('allowed', true)))->count(), // @phpstan-ignore argument.templateType
            'activeIps' => IpAddress::where('allowed', true)->count(),
            'blockedUsers' => User::where('blocked', true)->count(),
            'dhcpPools' => Inertia::defer(fn (): array => $this->getDhcpPools($dhcp)),
            'uniqueIps' => Inertia::defer(fn (): array => $this->getUniqueIpsOverTime()),
            'recentUsers' => Inertia::defer(fn (): LengthAwarePaginator => $this->getRecentUsers()),
            'breadcrumbs' => [
                ['label' => 'Admin', 'href' => route('admin.home')],
                ['label' => 'Dashboard'],
            ],
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

    /** @return list<array{date: string, count: int}> */
    private function getUniqueIpsOverTime(): array
    {
        $start = CarbonImmutable::now()->subDays(6)->startOfDay();

        /** @var list<array{date: string, count: int}> $results */
        $results = DB::table('ip_addresses')
            ->selectRaw('DATE(last_seen_at) as date, COUNT(DISTINCT id) as count')
            ->where('last_seen_at', '>=', $start)
            ->groupByRaw('DATE(last_seen_at)')
            ->orderBy('date')
            ->get()
            ->map(fn (object $row): array => [
                'date' => (string) $row->date,
                'count' => (int) $row->count,
            ])
            ->all();

        return $results;
    }

    /** @return LengthAwarePaginator<int, User> */
    private function getRecentUsers(): LengthAwarePaginator
    {
        return User::query()
            ->select('users.*')
            ->selectRaw('(SELECT COUNT(*) FROM user_ip_addresses WHERE user_ip_addresses.user_id = users.id) as ips_count')
            ->selectRaw('(SELECT COALESCE(SUM(ip_addresses.received + ip_addresses.sent), 0) FROM user_ip_addresses INNER JOIN ip_addresses ON ip_addresses.id = user_ip_addresses.ip_address_id WHERE user_ip_addresses.user_id = users.id) as total_bandwidth')
            ->selectRaw('(SELECT MAX(user_ip_addresses.last_seen_at) FROM user_ip_addresses WHERE user_ip_addresses.user_id = users.id) as last_seen')
            ->orderByDesc('last_seen')
            ->orderByDesc('users.updated_at')
            ->paginate(25);
    }
}
