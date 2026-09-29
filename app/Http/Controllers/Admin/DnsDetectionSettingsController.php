<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateDnsDetectionSettingsRequest;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
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

    public function update(UpdateDnsDetectionSettingsRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        Setting::set('dns.check_url', 'DNS Check URL', $validated['dns_check_url']);
        Setting::set('dns.warning_message', 'DNS Warning Message', $validated['dns_warning_message']);

        return back()->with('success', 'DNS detection settings updated.');
    }
}
