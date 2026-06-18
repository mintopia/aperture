<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;

class ThemeService
{
    /** @var array<string, mixed>|null */
    private ?array $cached = null;

    public function __construct(
        private readonly LogoService $logoService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getTheme(): array
    {
        if ($this->cached !== null) {
            return $this->cached;
        }

        $this->cached = [
            'mode' => (string) Setting::get('theme.mode', config('aperture.theme.mode')),
            'accent_hue' => (int) Setting::get('theme.accent_hue', config('aperture.theme.accent_hue')),
            'accent_chroma' => (float) Setting::get('theme.accent_chroma', config('aperture.theme.accent_chroma')),
            'accent_lightness' => (int) Setting::get('theme.accent_lightness', config('aperture.theme.accent_lightness')),
            'custom_css' => Setting::get('theme.custom_css'),
            'site_title' => (string) Setting::get('general.site_title', config('app.name', 'Aperture')),
            'has_site_logo' => $this->logoService->exists(),
            'site_logo_url' => $this->logoService->url(),
            'favicon_urls' => $this->logoService->faviconUrls(),
        ];

        return $this->cached;
    }
}
