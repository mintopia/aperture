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
        $themeName = Setting::get('theme.name', 'cool-neon');
        $themeMode = Setting::get('theme.mode', 'dark');
        $siteTitle = Setting::get('theme.site_title', 'Aperture');
        $customColors = Setting::get('theme.custom_colors');
        $customCss = Setting::get('theme.custom_css');

        View::share('themeName', $themeName);
        View::share('themeMode', $themeMode);
        View::share('siteTitle', $siteTitle);
        View::share('customColors', $customColors);
        View::share('customCss', $customCss);

        return $next($request);
    }
}
