<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateCoverImageRequest;
use App\Http\Requests\Admin\UpdateGeneralSettingsRequest;
use App\Http\Requests\Admin\UpdateLogoRequest;
use App\Models\AuditLog;
use App\Models\Page;
use App\Models\Setting;
use App\Services\CoverImageService;
use App\Services\LogoService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class GeneralSettingsController extends Controller
{
    public function __construct(
        private readonly LogoService $logoService,
        private readonly CoverImageService $coverImageService,
    ) {}

    public function show(): Response
    {
        return Inertia::render('Admin/Content/Settings', [
            'settings' => [
                'site_title' => Setting::get('general.site_title', ''),
                'terms_type' => Setting::get('general.terms_type', 'url'),
                'terms_value' => Setting::get('general.terms_value'),
                'privacy_type' => Setting::get('general.privacy_type', 'url'),
                'privacy_value' => Setting::get('general.privacy_value'),
                'theme_mode' => Setting::get('theme.mode', config('aperture.theme.mode')),
                'accent_hue' => (int) Setting::get('theme.accent_hue', config('aperture.theme.accent_hue')),
                'accent_chroma' => (float) Setting::get('theme.accent_chroma', config('aperture.theme.accent_chroma')),
                'accent_lightness' => (int) Setting::get('theme.accent_lightness', config('aperture.theme.accent_lightness')),
                'custom_css' => Setting::get('theme.custom_css'),
                'site_logo_url' => $this->logoService->url(),
                'cover_image_url' => $this->coverImageService->url(),
            ],
            'pages' => Page::orderBy('title')->get(),
            'breadcrumbs' => [
                ['label' => 'Admin', 'href' => route('admin.home')],
                ['label' => 'Content', 'href' => route('admin.content.index')],
                ['label' => 'Settings'],
            ],
        ]);
    }

    public function update(UpdateGeneralSettingsRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        Setting::set('general.site_title', 'Site Title', $validated['site_title']);
        Setting::set('general.terms_type', 'Terms Type', $validated['terms_type']);
        Setting::set('general.terms_value', 'Terms Value', $validated['terms_value'] ?? null);
        Setting::set('general.privacy_type', 'Privacy Type', $validated['privacy_type']);
        Setting::set('general.privacy_value', 'Privacy Value', $validated['privacy_value'] ?? null);

        Setting::set('theme.mode', 'Theme Mode', $validated['theme_mode']);
        Setting::set('theme.accent_hue', 'Accent Hue', (string) $validated['accent_hue']);
        Setting::set('theme.accent_chroma', 'Accent Chroma', (string) ($validated['accent_chroma'] ?? config('aperture.theme.accent_chroma')));
        Setting::set('theme.accent_lightness', 'Accent Lightness', (string) ($validated['accent_lightness'] ?? config('aperture.theme.accent_lightness')));
        Setting::set('theme.custom_css', 'Custom CSS', $validated['custom_css'] ?? null);

        AuditLog::record(
            action: 'settings.updated',
            process: 'admin',
            metadata: ['ip' => $request->getClientIp(), 'setting_group' => 'general'],
        );

        return back()->with('success', 'Settings updated.');
    }

    public function updateLogo(UpdateLogoRequest $request): RedirectResponse
    {
        $this->logoService->store($request->file('logo'));

        AuditLog::record(
            action: 'settings.updated',
            process: 'admin',
            metadata: ['ip' => $request->getClientIp(), 'setting_group' => 'logo'],
        );

        return back()->with('success', 'Logo uploaded.');
    }

    public function deleteLogo(): RedirectResponse
    {
        $this->logoService->delete();

        return back()->with('success', 'Logo removed.');
    }

    public function updateCoverImage(UpdateCoverImageRequest $request): RedirectResponse
    {
        $this->coverImageService->store($request->file('cover_image'));

        AuditLog::record(
            action: 'settings.updated',
            process: 'admin',
            metadata: ['ip' => $request->getClientIp(), 'setting_group' => 'cover_image'],
        );

        return back()->with('success', 'Cover image uploaded.');
    }

    public function deleteCoverImage(): RedirectResponse
    {
        $this->coverImageService->delete();

        return back()->with('success', 'Cover image removed.');
    }
}
