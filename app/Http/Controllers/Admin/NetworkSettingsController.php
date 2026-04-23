<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\NetworkRangeService;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'managed_ranges_v4' => ['nullable', 'string', $this->cidrValidationRule(4)],
            'managed_ranges_v6' => ['nullable', 'string', $this->cidrValidationRule(6)],
            'dns_filter_default' => 'boolean',
            'oui_auto_allow' => ['nullable', 'string', $this->ouiValidationRule()],
        ]);

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

    /**
     * @return list<string>
     */
    private function parseLines(string $text): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", $text)), fn (string $line): bool => $line !== ''));
    }

    /**
     * @param  4|6  $family
     */
    private function cidrValidationRule(int $family): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($family): void {
            if ($value === null || $value === '') {
                return;
            }

            $lines = $this->parseLines((string) $value);
            foreach ($lines as $line) {
                if (! $this->isValidCidr($line, $family)) {
                    $label = $family === 4 ? 'IPv4' : 'IPv6';
                    $fail(sprintf('Invalid %s CIDR notation: %s', $label, $line));

                    return;
                }
            }
        };
    }

    /**
     * @param  4|6  $family
     */
    private function isValidCidr(string $cidr, int $family): bool
    {
        return NetworkRangeService::isValidCidr($cidr, $family);
    }

    private function ouiValidationRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value === null || $value === '') {
                return;
            }

            $lines = $this->parseLines((string) $value);
            foreach ($lines as $line) {
                if (! preg_match('/^([0-9A-Fa-f]{2}:){0,5}[0-9A-Fa-f]{2}$/', $line)) {
                    $fail('Invalid OUI prefix: '.$line);

                    return;
                }
            }
        };
    }
}
