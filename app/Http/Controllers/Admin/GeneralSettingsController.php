<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GeneralSettingsController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Admin/Content/Settings', [
            'settings' => [
                'site_title' => Setting::get('general.site_title', ''),
                'dns_filtering_default' => (bool) Setting::get('general.dns_filtering_default', false),
                'terms_type' => Setting::get('general.terms_type', 'url'),
                'terms_value' => Setting::get('general.terms_value'),
                'privacy_type' => Setting::get('general.privacy_type', 'url'),
                'privacy_value' => Setting::get('general.privacy_value'),
            ],
            'pages' => Page::orderBy('title')->get(),
            'breadcrumbs' => [
                ['label' => 'Admin', 'href' => route('admin.home')],
                ['label' => 'Content', 'href' => route('admin.content.index')],
                ['label' => 'Settings'],
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'site_title' => 'required|string|max:255',
            'dns_filtering_default' => 'boolean',
            'terms_type' => 'required|string|in:page,url',
            'terms_value' => 'nullable|string|max:500',
            'privacy_type' => 'required|string|in:page,url',
            'privacy_value' => 'nullable|string|max:500',
        ]);

        $this->saveSetting('general.site_title', 'Site Title', $validated['site_title']);
        $this->saveSetting('general.dns_filtering_default', 'DNS Filtering Default', $validated['dns_filtering_default'] ? '1' : '0');
        $this->saveSetting('general.terms_type', 'Terms Type', $validated['terms_type']);
        $this->saveSetting('general.terms_value', 'Terms Value', $validated['terms_value'] ?? null);
        $this->saveSetting('general.privacy_type', 'Privacy Type', $validated['privacy_type']);
        $this->saveSetting('general.privacy_value', 'Privacy Value', $validated['privacy_value'] ?? null);

        return back()->with('success', 'General settings updated.');
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
