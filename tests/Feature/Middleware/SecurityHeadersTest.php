<?php

declare(strict_types=1);

namespace Tests\Feature\Middleware;

use App\Models\IntegrationConfig;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_x_content_type_options_header_is_present(): void
    {
        $response = $this->get('/');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_x_frame_options_header_is_present(): void
    {
        $response = $this->get('/');

        $response->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_referrer_policy_header_is_present(): void
    {
        $response = $this->get('/');

        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_permissions_policy_header_is_present(): void
    {
        $response = $this->get('/');

        $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
    }

    public function test_hsts_header_present_when_app_url_is_https(): void
    {
        config(['app.url' => 'https://example.com']);

        $response = $this->get('/');

        $response->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_hsts_header_absent_when_app_url_is_http(): void
    {
        config(['app.url' => 'http://example.com']);

        $response = $this->get('/');

        $response->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_content_security_policy_header_is_present(): void
    {
        config(['reverb.frontend.host' => 'ws.example.com', 'reverb.frontend.scheme' => 'https', 'reverb.frontend.port' => 443]);

        $csp = $this->get('/')->headers->get('Content-Security-Policy');

        $this->assertNotNull($csp);
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9+\\/=]+'/", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString('wss://ws.example.com:443', $csp);
        $this->assertStringContainsString("img-src 'self' data:", $csp);
        $this->assertStringNotContainsString('localhost:5173', $csp);
    }

    public function test_csp_nonce_differs_per_request(): void
    {
        $a = $this->get('/')->headers->get('Content-Security-Policy');
        $b = $this->get('/')->headers->get('Content-Security-Policy');

        $this->assertNotSame($a, $b);
    }

    public function test_csp_allows_vite_dev_origin_only_when_hot_file_exists(): void
    {
        $hot = public_path('hot');
        file_put_contents($hot, 'http://localhost:5173');

        try {
            $csp = $this->get('/')->headers->get('Content-Security-Policy');
        } finally {
            unlink($hot);
        }

        $this->assertStringContainsString('http://localhost:5173', $csp);
        $this->assertStringContainsString('ws://localhost:5173', $csp);
    }

    public function test_csp_connect_src_allows_configured_detection_origins(): void
    {
        Setting::set('dns.check_url', 'DNS check URL', 'https://{uuid}.dns.example.com/check');
        IntegrationConfig::setValue('ipv6', 'detection_endpoint', 'https://v6.example.net:8443/{uuid}');

        $csp = (string) $this->get('/')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString('https://*.dns.example.com', $csp);
        $this->assertStringContainsString('https://v6.example.net:8443', $csp);
    }

    public function test_csp_connect_src_ignores_unusable_detection_urls(): void
    {
        Setting::set('dns.check_url', 'DNS check URL', 'https://dns-{uuid}.example.com/');
        IntegrationConfig::setValue('ipv6', 'detection_endpoint', 'not a url');

        $csp = (string) $this->get('/')->headers->get('Content-Security-Policy');

        $this->assertStringNotContainsString('example.com', $csp);
        $this->assertStringNotContainsString('not a url', $csp);
    }
}
