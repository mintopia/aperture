<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'theme_name' => 'required|string|in:default,cool-neon,warm-neon,matrix,amber-glow',
            'theme_mode' => 'required|string|in:light,dark',
        ]);

        $this->saveSetting('theme.name', 'Theme Name', $validated['theme_name']);
        $this->saveSetting('theme.mode', 'Theme Mode', $validated['theme_mode']);

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
