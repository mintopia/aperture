<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\ThemeService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class InjectTheme
{
    public function __construct(
        private readonly ThemeService $themeService,
    ) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $theme = $this->themeService->getTheme();

        View::share('themeName', 'dispatch');
        View::share('themeMode', $theme['mode']);
        View::share('siteTitle', $theme['site_title']);
        View::share('accentHue', $theme['accent_hue']);
        View::share('accentChroma', $theme['accent_chroma']);
        View::share('accentLightness', $theme['accent_lightness']);
        View::share('customCss', $theme['custom_css']);

        return $next($request);
    }
}
