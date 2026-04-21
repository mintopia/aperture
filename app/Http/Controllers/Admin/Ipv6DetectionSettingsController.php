<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\IntegrationConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class Ipv6DetectionSettingsController extends Controller
{
    public function show(): Response
    {
        $config = IntegrationConfig::getAll('ipv6');

        return Inertia::render('Admin/Settings/Ipv6Detection', [
            'settings' => [
                'detection_enabled' => (bool) ($config['detection_enabled'] ?? false),
                'detection_endpoint' => $config['detection_endpoint'] ?? '',
                'jwks_url' => $config['jwks_url'] ?? '',
            ],
            'breadcrumbs' => [
                ['label' => 'Admin', 'href' => route('admin.home')],
                ['label' => 'Settings'],
                ['label' => 'IPv6 Detection'],
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'detection_enabled' => 'required|boolean',
            'detection_endpoint' => ['nullable', 'string', 'max:500', 'regex:/^https:\/\/.+/'],
            'jwks_url' => 'nullable|url:https|max:500',
        ]);

        IntegrationConfig::setValue('ipv6', 'detection_enabled', $validated['detection_enabled'] ? '1' : '0');
        IntegrationConfig::setValue('ipv6', 'detection_endpoint', $validated['detection_endpoint'] ?? '');
        IntegrationConfig::setValue('ipv6', 'jwks_url', $validated['jwks_url'] ?? '');

        return back()->with('success', 'IPv6 detection settings updated.');
    }
}
