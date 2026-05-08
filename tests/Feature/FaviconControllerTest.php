<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\ThemeService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class FaviconControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_returns_svg_content_type(): void
    {
        $response = $this->get('/favicon.svg');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/svg+xml');
    }

    public function test_returns_valid_svg(): void
    {
        $response = $this->get('/favicon.svg');

        $body = $response->getContent();
        $this->assertIsString($body);
        $this->assertStringContainsString('<svg', $body);
        $this->assertStringContainsString('</svg>', $body);
        $this->assertStringContainsString('viewBox="0 0 24 24"', $body);
    }

    public function test_uses_default_accent_color(): void
    {
        $response = $this->get('/favicon.svg');

        $body = $response->getContent();
        $this->assertIsString($body);
        $this->assertStringContainsString('stroke="', $body);
        $this->assertStringNotContainsString('#fc913d', $body);
    }

    public function test_respects_custom_accent_hue(): void
    {
        Setting::set('theme.accent_hue', 'Accent Hue', '230');
        Setting::set('theme.accent_chroma', 'Accent Chroma', '0.14');
        Setting::set('theme.accent_lightness', 'Accent Lightness', '72');

        $response = $this->get('/favicon.svg');

        $body = $response->getContent();
        $this->assertIsString($body);
        $this->assertStringContainsString('stroke="', $body);
        $this->assertMatchesRegularExpression('/stroke="#[0-9a-f]{6}"/', $body);
    }

    public function test_different_hues_produce_different_colors(): void
    {
        $response1 = $this->get('/favicon.svg');
        $body1 = $response1->getContent();

        Setting::set('theme.accent_hue', 'Accent Hue', '230');
        Setting::set('theme.accent_chroma', 'Accent Chroma', '0.14');
        Setting::set('theme.accent_lightness', 'Accent Lightness', '72');

        app()->forgetInstance(ThemeService::class);

        $response2 = $this->get('/favicon.svg');
        $body2 = $response2->getContent();

        $this->assertNotEquals($body1, $body2);
    }

    public function test_response_is_cacheable(): void
    {
        $response = $this->get('/favicon.svg');

        $response->assertHeader('Cache-Control');
        $cacheControl = $response->headers->get('Cache-Control');
        $this->assertStringContainsString('public', $cacheControl);
    }

    public function test_does_not_require_authentication(): void
    {
        $response = $this->get('/favicon.svg');

        $response->assertOk();
    }
}
