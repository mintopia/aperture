<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateThemeSettingsRequest;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ThemeSettingsController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Admin/Settings/Theme', [
            'settings' => [
                'theme_mode' => Setting::get('theme.mode', config('aperture.theme.mode')),
                'accent_hue' => (int) Setting::get('theme.accent_hue', config('aperture.theme.accent_hue')),
                'accent_chroma' => (float) Setting::get('theme.accent_chroma', config('aperture.theme.accent_chroma')),
                'accent_lightness' => (int) Setting::get('theme.accent_lightness', config('aperture.theme.accent_lightness')),
                'custom_css' => Setting::get('theme.custom_css'),
            ],
            'breadcrumbs' => [
                ['label' => 'Admin', 'href' => route('admin.home')],
                ['label' => 'Content', 'href' => route('admin.content.index')],
                ['label' => 'Theme'],
            ],
        ]);
    }

    public function update(UpdateThemeSettingsRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        Setting::set('theme.mode', 'Theme Mode', $validated['theme_mode']);
        Setting::set('theme.accent_hue', 'Accent Hue', (string) $validated['accent_hue']);
        Setting::set('theme.accent_chroma', 'Accent Chroma', (string) ($validated['accent_chroma'] ?? config('aperture.theme.accent_chroma')));
        Setting::set('theme.accent_lightness', 'Accent Lightness', (string) ($validated['accent_lightness'] ?? config('aperture.theme.accent_lightness')));
        Setting::set('theme.custom_css', 'Custom CSS', $validated['custom_css'] ?? null);

        return back()->with('success', 'Theme settings updated.');
    }
}
