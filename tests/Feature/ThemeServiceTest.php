<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\ThemeService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ThemeServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_returns_default_theme_values(): void
    {
        // Remove migration-seeded settings to test defaults
        Setting::whereIn('code', ['theme.name', 'theme.mode'])->delete();

        $service = app(ThemeService::class);
        $theme = $service->getTheme();

        $this->assertEquals(config('aperture.theme.mode'), $theme['mode']);
        $this->assertEquals(config('aperture.theme.accent_hue'), $theme['accent_hue']);
        $this->assertEqualsWithDelta(config('aperture.theme.accent_chroma'), $theme['accent_chroma'], 0.001);
        $this->assertEquals(config('aperture.theme.accent_lightness'), $theme['accent_lightness']);
        $this->assertNull($theme['custom_css']);
        $this->assertEquals('Aperture', $theme['site_title']);
    }

    public function test_reads_theme_from_settings(): void
    {
        $this->saveSetting('theme.mode', 'Theme Mode', 'light');
        $this->saveSetting('theme.accent_hue', 'Accent Hue', '230');
        $this->saveSetting('theme.accent_chroma', 'Accent Chroma', '0.25');
        $this->saveSetting('theme.accent_lightness', 'Accent Lightness', '68');
        $this->saveSetting('theme.custom_css', 'Custom CSS', 'body { color: red; }');
        $this->saveSetting('general.site_title', 'Site Title', 'My App');

        $service = app(ThemeService::class);
        $theme = $service->getTheme();

        $this->assertEquals('light', $theme['mode']);
        $this->assertEquals(230, $theme['accent_hue']);
        $this->assertEqualsWithDelta(0.25, $theme['accent_chroma'], 0.001);
        $this->assertEquals(68, $theme['accent_lightness']);
        $this->assertEquals('body { color: red; }', $theme['custom_css']);
        $this->assertEquals('My App', $theme['site_title']);
    }

    public function test_caches_result_on_subsequent_calls(): void
    {
        $service = app(ThemeService::class);

        $first = $service->getTheme();
        $second = $service->getTheme();

        $this->assertSame($first, $second);
    }

    public function test_scoped_singleton_returns_same_instance(): void
    {
        $first = app(ThemeService::class);
        $second = app(ThemeService::class);

        $this->assertSame($first, $second);
    }

    public function test_falls_back_to_config_when_no_db_settings(): void
    {
        // Remove migration-seeded settings
        Setting::whereIn('code', ['theme.name', 'theme.mode'])->delete();

        config(['aperture.theme.mode' => 'light']);
        config(['aperture.theme.accent_hue' => 200]);
        config(['aperture.theme.accent_chroma' => 0.25]);
        config(['aperture.theme.accent_lightness' => 80]);

        $service = app(ThemeService::class);
        $theme = $service->getTheme();

        $this->assertEquals('light', $theme['mode']);
        $this->assertEquals(200, $theme['accent_hue']);
        $this->assertEqualsWithDelta(0.25, $theme['accent_chroma'], 0.001);
        $this->assertEquals(80, $theme['accent_lightness']);
    }

    public function test_db_settings_override_config_defaults(): void
    {
        config(['aperture.theme.mode' => 'light']);

        // Migration seeds theme.mode as 'dark' — DB should win
        $service = app(ThemeService::class);
        $theme = $service->getTheme();

        $this->assertEquals('dark', $theme['mode']);
    }

    private function saveSetting(string $code, string $name, mixed $value): void
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
