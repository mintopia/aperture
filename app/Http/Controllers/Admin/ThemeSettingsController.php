<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

class ThemeSettingsController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Admin/Settings/Theme', [
            'settings' => [
                'theme_mode' => Setting::get('theme.mode', 'dark'),
                'accent_hue' => (int) Setting::get('theme.accent_hue', 55),
                'accent_chroma' => (float) Setting::get('theme.accent_chroma', '0.19'),
                'accent_lightness' => (int) Setting::get('theme.accent_lightness', 72),
                'site_title' => Setting::get('theme.site_title', 'Aperture'),
                'custom_css' => Setting::get('theme.custom_css'),
            ],
            'breadcrumbs' => [
                ['label' => 'Admin', 'href' => route('admin.home')],
                ['label' => 'Settings'],
                ['label' => 'Theme'],
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'theme_mode' => 'required|string|in:light,dark',
            'accent_hue' => 'required|integer|min:0|max:360',
            'accent_chroma' => 'nullable|numeric|min:0.01|max:0.37',
            'accent_lightness' => 'nullable|integer|min:40|max:95',
            'site_title' => 'nullable|string|max:255',
            'custom_css' => ['nullable', 'string', 'max:10000', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && stripos($value, '<script') !== false) {
                    $fail('The custom CSS must not contain script tags.');
                }
            }],
        ]);

        $validated = $validator->validate();

        $this->saveSetting('theme.mode', 'Theme Mode', $validated['theme_mode']);
        $this->saveSetting('theme.accent_hue', 'Accent Hue', (string) $validated['accent_hue']);
        $this->saveSetting('theme.accent_chroma', 'Accent Chroma', (string) ($validated['accent_chroma'] ?? 0.19));
        $this->saveSetting('theme.accent_lightness', 'Accent Lightness', (string) ($validated['accent_lightness'] ?? 72));
        $this->saveSetting('theme.site_title', 'Site Title', $validated['site_title'] ?? null);
        $this->saveSetting('theme.custom_css', 'Custom CSS', $validated['custom_css'] ?? null);

        return back()->with('success', 'Theme settings updated.');
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
