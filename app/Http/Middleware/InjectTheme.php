<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class InjectTheme
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $themeMode = Setting::get('theme.mode', 'dark');
        $siteTitle = Setting::get('theme.site_title', 'Aperture');
        $accentHue = (int) Setting::get('theme.accent_hue', 55);
        $customCss = Setting::get('theme.custom_css');

        View::share('themeName', 'dispatch');
        View::share('themeMode', $themeMode);
        View::share('siteTitle', $siteTitle);
        View::share('accentHue', $accentHue);
        View::share('customCss', $customCss);

        return $next($request);
    }
}
