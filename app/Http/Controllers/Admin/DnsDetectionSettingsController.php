<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DnsDetectionSettingsController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Admin/Settings/DnsDetection', [
            'settings' => [
                'dns_check_url' => Setting::get('dns.check_url', ''),
                'dns_warning_message' => Setting::get('dns.warning_message', ''),
            ],
            'breadcrumbs' => [
                ['label' => 'Admin', 'href' => route('admin.home')],
                ['label' => 'Services'],
                ['label' => 'DNS Detection'],
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'dns_check_url' => [
                'nullable',
                'string',
                'max:500',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value !== null && $value !== '' && ! str_contains($value, '{uuid}')) {
                        $fail('The URL must contain the {uuid} placeholder.');
                    }
                },
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value !== null && $value !== '' && ! filter_var(str_replace('{uuid}', 'test', $value), FILTER_VALIDATE_URL)) {
                        $fail('The URL must be a valid URL.');
                    }
                },
            ],
            'dns_warning_message' => 'nullable|string|max:500',
        ]);

        $this->saveSetting('dns.check_url', 'DNS Check URL', $validated['dns_check_url']);
        $this->saveSetting('dns.warning_message', 'DNS Warning Message', $validated['dns_warning_message']);

        return back()->with('success', 'DNS detection settings updated.');
    }

    protected function saveSetting(string $code, string $name, mixed $value): void
    {
        $setting = Setting::whereCode($code)->first();
        if (! $setting) {
            $setting = new Setting;
            $setting->code = $code;
            $setting->name = $name;
        }

        $setting->value = $value;
        $setting->save();
    }
}
