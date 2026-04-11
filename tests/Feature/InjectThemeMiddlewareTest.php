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
        $setting = Setting::whereCode('theme.name')->first() ?? new Setting;
        $setting->code = 'theme.name';
        $setting->name = 'Theme Name';
        $setting->value = 'matrix';
        $setting->save();

        $setting = Setting::whereCode('theme.mode')->first() ?? new Setting;
        $setting->code = 'theme.mode';
        $setting->name = 'Theme Mode';
        $setting->value = 'light';
        $setting->save();

        $middleware = new InjectTheme;
        $request = Request::create('/');

        $middleware->handle($request, function ($req): ResponseFactory|Response {
            $shared = View::getShared();
            $this->assertEquals('matrix', $shared['themeName']);
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
            $this->assertEquals('cool-neon', $shared['themeName']);
            $this->assertEquals('dark', $shared['themeMode']);

            return response('OK');
        });
    }
}
