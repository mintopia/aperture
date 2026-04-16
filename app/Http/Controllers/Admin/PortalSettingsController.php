<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PortalSettingsController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Admin/Settings/Portal', [
            'settings' => [
                'portal_session_timeout' => Setting::get('portal.session_timeout', 86400),
                'portal_redirect_url' => Setting::get('portal.redirect_url', ''),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
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
