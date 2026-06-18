<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Setting;
use App\Services\ThemeService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function __construct(
        private readonly ThemeService $themeService,
    ) {}

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
            'appName' => fn (): string => $this->themeService->getTheme()['site_title'],
            'footer' => fn (): array => [
                'terms_type' => Setting::get('general.terms_type'),
                'terms_value' => Setting::get('general.terms_value'),
                'privacy_type' => Setting::get('general.privacy_type'),
                'privacy_value' => Setting::get('general.privacy_value'),
            ],
            'theme' => fn (): array => [
                'mode' => $this->themeService->getTheme()['mode'],
                'accent_hue' => $this->themeService->getTheme()['accent_hue'],
                'accent_chroma' => $this->themeService->getTheme()['accent_chroma'],
                'accent_lightness' => $this->themeService->getTheme()['accent_lightness'],
            ],
        ]);
    }
}
