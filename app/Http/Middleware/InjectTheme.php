<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Rules\SafeCss;
use App\Services\ThemeService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
        $customCss = $theme['custom_css'];
        if (is_string($customCss) && SafeCss::containsDangerousPatterns($customCss)) {
            Log::warning('Blocked rendering of custom CSS containing dangerous patterns.');
            $customCss = null;
        }
        View::share('customCss', $customCss);
        View::share('siteLogoUrl', $theme['site_logo_url']);
        View::share('hasSiteLogo', $theme['has_site_logo']);
        View::share('faviconUrls', $theme['favicon_urls']);

        return $next($request);
    }
}
