<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\ThemeService;
use Illuminate\Http\Response;

class FaviconController extends Controller
{
    public function __invoke(ThemeService $themeService): Response
    {
        $theme = $themeService->getTheme();

        $hue = (int) $theme['accent_hue'];
        $chroma = (float) $theme['accent_chroma'];
        $lightness = (int) $theme['accent_lightness'];

        $hex = self::oklchToHex($lightness, $chroma, $hue);

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="'.$hex.'" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">'
            .'<circle cx="12" cy="12" r="10"/>'
            .'<line x1="14.31" y1="8" x2="20.05" y2="17.94"/>'
            .'<line x1="9.69" y1="8" x2="21.17" y2="8"/>'
            .'<line x1="7.38" y1="12" x2="13.12" y2="2.06"/>'
            .'<line x1="9.69" y1="16" x2="3.95" y2="6.06"/>'
            .'<line x1="14.31" y1="16" x2="2.83" y2="16"/>'
            .'<line x1="16.62" y1="12" x2="10.88" y2="21.94"/>'
            .'</svg>';

        return new Response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    public static function oklchToHex(float $lightness, float $chroma, float $hue): string
    {
        $l = $lightness / 100.0;
        $hRad = $hue * M_PI / 180.0;

        $a = $chroma * cos($hRad);
        $b = $chroma * sin($hRad);

        $l_ = $l + 0.3963377774 * $a + 0.2158037573 * $b;
        $m_ = $l - 0.1055613458 * $a - 0.0638541728 * $b;
        $s_ = $l - 0.0894841775 * $a - 1.2914855480 * $b;

        $lCubed = $l_ * $l_ * $l_;
        $mCubed = $m_ * $m_ * $m_;
        $sCubed = $s_ * $s_ * $s_;

        $r = 4.0767416621 * $lCubed - 3.3077115913 * $mCubed + 0.2309699292 * $sCubed;
        $g = -1.2684380046 * $lCubed + 2.6097574011 * $mCubed - 0.3413193965 * $sCubed;
        $bRgb = -0.0041960863 * $lCubed - 0.7034186147 * $mCubed + 1.7076147010 * $sCubed;

        $r = self::linearToSrgb($r);
        $g = self::linearToSrgb($g);
        $bRgb = self::linearToSrgb($bRgb);

        $r = max(0, min(255, (int) round($r * 255)));
        $g = max(0, min(255, (int) round($g * 255)));
        $bRgb = max(0, min(255, (int) round($bRgb * 255)));

        return sprintf('#%02x%02x%02x', $r, $g, $bRgb);
    }

    private static function linearToSrgb(float $c): float
    {
        if ($c <= 0.0031308) {
            return $c * 12.92;
        }

        return 1.055 * ($c ** (1.0 / 2.4)) - 0.055;
    }
}
