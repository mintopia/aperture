<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateCaptivePortalApiSettingsRequest;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class CaptivePortalApiSettingsController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Admin/Settings/CaptivePortalApi', [
            'settings' => [
                'user_portal_url' => Setting::get('captive_portal_api.user_portal_url', ''),
                'venue_info_url' => Setting::get('captive_portal_api.venue_info_url', ''),
                'can_extend_session' => Setting::get('captive_portal_api.can_extend_session', '0') === '1',
            ],
            'apiUrl' => url('/api/captive-portal'),
            'breadcrumbs' => [
                ['label' => 'Admin', 'href' => route('admin.home')],
                ['label' => 'Services'],
                ['label' => 'RFC 8908 API'],
            ],
        ]);
    }

    public function update(UpdateCaptivePortalApiSettingsRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        Setting::set('captive_portal_api.user_portal_url', 'Captive Portal User URL', $validated['user_portal_url'] ?? '');
        Setting::set('captive_portal_api.venue_info_url', 'Captive Portal Venue URL', $validated['venue_info_url'] ?? '');
        Setting::set('captive_portal_api.can_extend_session', 'Captive Portal Can Extend Session', $validated['can_extend_session'] ? '1' : '0');

        return back()->with('success', 'Captive portal API settings updated.');
    }
}
