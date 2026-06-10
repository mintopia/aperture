<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateNetworkSettingsRequest;
use App\Models\AuditLog;
use App\Models\IpAddressMacAddress;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class NetworkSettingsController extends Controller
{
    public function show(): Response
    {
        $v4Raw = Setting::get('network.managed_ranges_v4');
        $v6Raw = Setting::get('network.managed_ranges_v6');

        $v4Decoded = $v4Raw !== null ? json_decode((string) $v4Raw, true) : null;
        $v6Decoded = $v6Raw !== null ? json_decode((string) $v6Raw, true) : null;

        $v4Ranges = is_array($v4Decoded) ? $v4Decoded : ['0.0.0.0/0'];
        $v6Ranges = is_array($v6Decoded) ? $v6Decoded : ['::/0'];

        $ouiRaw = Setting::get('network.oui_auto_allow');
        $ouiDecoded = $ouiRaw !== null ? json_decode((string) $ouiRaw, true) : null;
        $ouiPrefixes = is_array($ouiDecoded) ? $ouiDecoded : [];

        return Inertia::render('Admin/Settings/Network', [
            'settings' => [
                'managed_ranges_v4' => implode("\n", $v4Ranges),
                'managed_ranges_v6' => implode("\n", $v6Ranges),
                'dns_filter_default' => (bool) Setting::get('network.dns_filter_default', false),
                'oui_auto_allow' => implode("\n", $ouiPrefixes),
            ],
            'breadcrumbs' => [
                ['label' => 'Admin', 'href' => route('admin.home')],
                ['label' => 'Services'],
                ['label' => 'Network'],
            ],
        ]);
    }

    public function update(UpdateNetworkSettingsRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $v4Lines = $this->parseLines($validated['managed_ranges_v4'] ?? '');
        $v6Lines = $this->parseLines($validated['managed_ranges_v6'] ?? '');

        Setting::set('network.managed_ranges_v4', 'Managed IPv4 Ranges', json_encode($v4Lines));
        Setting::set('network.managed_ranges_v6', 'Managed IPv6 Ranges', json_encode($v6Lines));
        Setting::set('network.dns_filter_default', 'DNS Filter Default', ($validated['dns_filter_default'] ?? false) ? '1' : '0');

        $ouiLines = $this->parseLines($validated['oui_auto_allow'] ?? '');
        $ouiNormalized = array_map('strtoupper', $ouiLines);
        Setting::set('network.oui_auto_allow', 'OUI Auto-Allow Prefixes', json_encode($ouiNormalized));

        return back()->with('success', 'Network settings updated.');
    }

    public function clearIpMacMappings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'days' => ['required', 'integer', 'min:1', 'max:3650'],
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

        $days = (int) $validated['days'];

        $deleted = IpAddressMacAddress::where('last_seen_at', '<', now()->subDays($days))->delete();

        AuditLog::record(
            action: 'network.ip_mac_mappings.cleared',
            subject: $user,
            actor: $user,
            process: 'admin',
            metadata: ['days' => $days, 'deleted' => $deleted, 'ip' => $request->getClientIp()],
        );

        return back()->with('success', sprintf('Cleared %s IP to MAC mappings older than %d days.', $deleted, $days));
    }

    /**
     * @return list<string>
     */
    private function parseLines(string $text): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", $text)), fn (string $line): bool => $line !== ''));
    }
}
