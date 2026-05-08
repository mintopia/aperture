<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Controllers\FaviconController;
use PHPUnit\Framework\TestCase;

class OklchToHexTest extends TestCase
{
    public function test_default_tangerine_produces_warm_orange(): void
    {
        $hex = FaviconController::oklchToHex(76, 0.16, 55);

        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $hex);
        $r = hexdec(substr($hex, 1, 2));
        $g = hexdec(substr($hex, 3, 2));
        $b = hexdec(substr($hex, 5, 2));
        $this->assertGreaterThan($g, $r);
        $this->assertGreaterThan($b, $r);
    }

    public function test_blue_hue_produces_blue_color(): void
    {
        $hex = FaviconController::oklchToHex(72, 0.14, 230);

        $r = hexdec(substr($hex, 1, 2));
        $g = hexdec(substr($hex, 3, 2));
        $b = hexdec(substr($hex, 5, 2));
        $this->assertGreaterThan($r, $b);
    }

    public function test_pink_hue_produces_reddish_color(): void
    {
        $hex = FaviconController::oklchToHex(72, 0.19, 350);

        $r = hexdec(substr($hex, 1, 2));
        $g = hexdec(substr($hex, 3, 2));
        $b = hexdec(substr($hex, 5, 2));
        $this->assertGreaterThan($g, $r);
    }

    public function test_lime_hue_produces_greenish_color(): void
    {
        $hex = FaviconController::oklchToHex(80, 0.18, 135);

        $r = hexdec(substr($hex, 1, 2));
        $g = hexdec(substr($hex, 3, 2));
        $this->assertGreaterThan($r, $g);
    }

    public function test_zero_chroma_produces_neutral_grey(): void
    {
        $hex = FaviconController::oklchToHex(50, 0.0, 0);

        $r = hexdec(substr($hex, 1, 2));
        $g = hexdec(substr($hex, 3, 2));
        $b = hexdec(substr($hex, 5, 2));
        $this->assertEqualsWithDelta($r, $g, 2);
        $this->assertEqualsWithDelta($g, $b, 2);
    }

    public function test_all_preset_hues_produce_valid_hex(): void
    {
        $presets = [
            ['l' => 72, 'c' => 0.19, 'h' => 350],
            ['l' => 73, 'c' => 0.17, 'h' => 20],
            ['l' => 76, 'c' => 0.16, 'h' => 55],
            ['l' => 80, 'c' => 0.18, 'h' => 135],
            ['l' => 76, 'c' => 0.12, 'h' => 185],
            ['l' => 72, 'c' => 0.14, 'h' => 230],
            ['l' => 70, 'c' => 0.18, 'h' => 295],
            ['l' => 70, 'c' => 0.2, 'h' => 325],
        ];

        foreach ($presets as $preset) {
            $hex = FaviconController::oklchToHex($preset['l'], $preset['c'], $preset['h']);
            $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $hex, "Invalid hex for hue {$preset['h']}");
        }
    }
}
