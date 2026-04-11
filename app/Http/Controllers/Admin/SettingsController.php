<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function integrations(): Response
    {
        return Inertia::render('Admin/Settings/Integrations', [
            'settings' => [
                'opnsense_endpoint' => Setting::get('opnsense.endpoint', ''),
                'ntopng_endpoint' => Setting::get('ntopng.endpoint', ''),
                'borealis_endpoint' => Setting::get('borealis.endpoint', ''),
                'librenms_endpoint' => Setting::get('librenms.endpoint', ''),
            ],
        ]);
    }

    public function updateIntegrations(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'opnsense_endpoint' => 'nullable|url|max:500',
            'ntopng_endpoint' => 'nullable|url|max:500',
            'borealis_endpoint' => 'nullable|url|max:500',
            'librenms_endpoint' => 'nullable|url|max:500',
        ]);

        foreach ($validated as $key => $value) {
            $code = str_replace('_', '.', $key);
            $setting = Setting::whereCode($code)->first();
            if (! $setting) {
                $setting = new Setting;
                $setting->code = $code;
                $setting->name = ucwords(str_replace('_', ' ', $key));
            }

            $setting->value = $value;
            $setting->save();
        }

        return back()->with('success', 'Integration settings updated.');
    }

    public function theme(): Response
    {
        return Inertia::render('Admin/Settings/Theme', [
            'settings' => [
                'theme_name' => Setting::get('theme.name', 'cool-neon'),
                'theme_mode' => Setting::get('theme.mode', 'dark'),
            ],
        ]);
    }

    public function updateTheme(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'theme_name' => 'required|string|in:cool-neon,warm-neon,matrix,amber-glow',
            'theme_mode' => 'required|string|in:light,dark',
        ]);

        $this->saveSetting('theme.name', 'Theme Name', $validated['theme_name']);
        $this->saveSetting('theme.mode', 'Theme Mode', $validated['theme_mode']);

        return back()->with('success', 'Theme settings updated.');
    }

    public function event(): Response
    {
        return Inertia::render('Admin/Settings/Event', [
            'settings' => [
                'event_name' => Setting::get('event.name', ''),
                'event_description' => Setting::get('event.description', ''),
            ],
        ]);
    }

    public function updateEvent(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'event_name' => 'required|string|max:255',
            'event_description' => 'nullable|string',
        ]);

        $this->saveSetting('event.name', 'Event Name', $validated['event_name']);
        $this->saveSetting('event.description', 'Event Description', $validated['event_description']);

        return back()->with('success', 'Event settings updated.');
    }

    public function portal(): Response
    {
        return Inertia::render('Admin/Settings/Portal', [
            'settings' => [
                'portal_session_timeout' => Setting::get('portal.session_timeout', 86400),
                'portal_redirect_url' => Setting::get('portal.redirect_url', ''),
            ],
        ]);
    }

    public function updatePortal(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'portal_session_timeout' => 'required|integer|min:300',
            'portal_redirect_url' => 'nullable|url|max:500',
        ]);

        $this->saveSetting('portal.session_timeout', 'Session Timeout', $validated['portal_session_timeout']);
        $this->saveSetting('portal.redirect_url', 'Redirect URL', $validated['portal_redirect_url']);

        return back()->with('success', 'Portal settings updated.');
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
