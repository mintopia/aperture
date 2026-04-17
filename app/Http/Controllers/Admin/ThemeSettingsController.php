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
                'theme_name' => Setting::get('theme.name', 'cool-neon'),
                'theme_mode' => Setting::get('theme.mode', 'dark'),
                'site_title' => Setting::get('theme.site_title', 'Aperture'),
                'custom_colors' => Setting::get('theme.custom_colors'),
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
        $allowedColorKeys = ['primary', 'accent', 'success', 'warning', 'danger'];

        $validator = Validator::make($request->all(), [
            'theme_name' => 'required|string|in:default,cool-neon,warm-neon,matrix,amber-glow',
            'theme_mode' => 'required|string|in:light,dark',
            'site_title' => 'nullable|string|max:255',
            'custom_colors' => 'nullable|array',
            'custom_colors.primary' => 'nullable|string|regex:/^#[0-9a-fA-F]{6}$/',
            'custom_colors.accent' => 'nullable|string|regex:/^#[0-9a-fA-F]{6}$/',
            'custom_colors.success' => 'nullable|string|regex:/^#[0-9a-fA-F]{6}$/',
            'custom_colors.warning' => 'nullable|string|regex:/^#[0-9a-fA-F]{6}$/',
            'custom_colors.danger' => 'nullable|string|regex:/^#[0-9a-fA-F]{6}$/',
            'custom_css' => ['nullable', 'string', 'max:10000', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && stripos($value, '<script') !== false) {
                    $fail('The custom CSS must not contain script tags.');
                }
            }],
        ]);

        $validator->after(function ($validator) use ($request, $allowedColorKeys): void {
            $customColors = $request->input('custom_colors');

            if (! is_array($customColors)) {
                return;
            }

            foreach (array_keys($customColors) as $key) {
                if (! in_array($key, $allowedColorKeys, true)) {
                    $validator->errors()->add('custom_colors.'.$key, 'The selected color is invalid.');
                }
            }
        });

        $validated = $validator->validate();

        $this->saveSetting('theme.name', 'Theme Name', $validated['theme_name']);
        $this->saveSetting('theme.mode', 'Theme Mode', $validated['theme_mode']);
        $this->saveSetting('theme.site_title', 'Site Title', $validated['site_title'] ?? null);
        $this->saveSetting(
            'theme.custom_colors',
            'Custom Colors',
            isset($validated['custom_colors']) ? json_encode($validated['custom_colors']) : null
        );
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
