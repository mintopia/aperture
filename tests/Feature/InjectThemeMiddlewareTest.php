<?php

namespace Tests\Feature;

use App\Http\Middleware\InjectTheme;
use App\Models\Setting;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

class InjectThemeMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_theme_data_shared_with_blade_views(): void
    {
        $setting = Setting::whereCode('theme.mode')->first() ?? new Setting;
        $setting->code = 'theme.mode';
        $setting->name = 'Theme Mode';
        $setting->value = 'light';
        $setting->save();

        $middleware = new InjectTheme;
        $request = Request::create('/');

        $middleware->handle($request, function ($req): ResponseFactory|Response {
            $shared = View::getShared();
            $this->assertEquals('dispatch', $shared['themeName']);
            $this->assertEquals('light', $shared['themeMode']);

            return response('OK');
        });
    }

    public function test_falls_back_to_default_theme_when_setting_missing(): void
    {
        $middleware = new InjectTheme;
        $request = Request::create('/');

        $middleware->handle($request, function ($req): ResponseFactory|Response {
            $shared = View::getShared();
            $this->assertEquals('dispatch', $shared['themeName']);
            $this->assertEquals('dark', $shared['themeMode']);
            $this->assertEquals(55, $shared['accentHue']);

            return response('OK');
        });
    }

    public function test_injects_site_title_from_settings(): void
    {
        $setting = Setting::whereCode('theme.site_title')->first() ?? new Setting;
        $setting->code = 'theme.site_title';
        $setting->name = 'Site Title';
        $setting->value = 'My Custom Aperture';
        $setting->save();

        $middleware = new InjectTheme;
        $request = Request::create('/');

        $middleware->handle($request, function ($req): ResponseFactory|Response {
            $shared = View::getShared();
            $this->assertEquals('My Custom Aperture', $shared['siteTitle']);

            return response('OK');
        });
    }

    public function test_injects_default_site_title_when_not_set(): void
    {
        $middleware = new InjectTheme;
        $request = Request::create('/');

        $middleware->handle($request, function ($req): ResponseFactory|Response {
            $shared = View::getShared();
            $this->assertEquals('Aperture', $shared['siteTitle']);

            return response('OK');
        });
    }

    public function test_injects_accent_hue_from_settings(): void
    {
        $setting = Setting::whereCode('theme.accent_hue')->first() ?? new Setting;
        $setting->code = 'theme.accent_hue';
        $setting->name = 'Accent Hue';
        $setting->value = '230';
        $setting->save();

        $middleware = new InjectTheme;
        $request = Request::create('/');

        $middleware->handle($request, function ($req): ResponseFactory|Response {
            $shared = View::getShared();
            $this->assertEquals(230, $shared['accentHue']);

            return response('OK');
        });
    }

    public function test_injects_custom_css_from_settings(): void
    {
        $setting = Setting::whereCode('theme.custom_css')->first() ?? new Setting;
        $setting->code = 'theme.custom_css';
        $setting->name = 'Custom CSS';
        $setting->value = 'body { background: #000; }';
        $setting->save();

        $middleware = new InjectTheme;
        $request = Request::create('/');

        $middleware->handle($request, function ($req): ResponseFactory|Response {
            $shared = View::getShared();
            $this->assertEquals('body { background: #000; }', $shared['customCss']);

            return response('OK');
        });
    }

    public function test_injects_null_custom_fields_when_not_set(): void
    {
        $middleware = new InjectTheme;
        $request = Request::create('/');

        $middleware->handle($request, function ($req): ResponseFactory|Response {
            $shared = View::getShared();
            $this->assertArrayHasKey('accentHue', $shared);
            $this->assertArrayHasKey('customCss', $shared);
            $this->assertEquals(55, $shared['accentHue']);
            $this->assertNull($shared['customCss']);

            return response('OK');
        });
    }
}
