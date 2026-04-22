<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $request->user() ? [
                    'id' => $request->user()->id,
                    'nickname' => $request->user()->nickname,
                    'email' => $request->user()->email,
                    'avatar_url' => $request->user()->avatar_url,
                    'is_admin' => $request->user()->hasRole('admin'),
                    'has_password' => $request->user()->password !== null,
                    'has_passkeys' => $request->user()->webAuthnCredentials()->count() > 0,
                ] : null,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'warning' => fn () => $request->session()->get('warning'),
                'info' => fn () => $request->session()->get('info'),
            ],
            'appName' => fn (): string => (string) Setting::get('site_title', 'Aperture'),
            'theme' => [
                'mode' => fn (): mixed => Setting::get('theme.mode', 'dark'),
                'accent_hue' => fn (): int => (int) Setting::get('theme.accent_hue', 55),
                'accent_chroma' => fn (): float => (float) Setting::get('theme.accent_chroma', '0.19'),
                'accent_lightness' => fn (): int => (int) Setting::get('theme.accent_lightness', 72),
            ],
        ]);
    }
}
