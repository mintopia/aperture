<?php

declare(strict_types=1);

namespace Tests\Feature\Middleware;

use App\Models\User;
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

        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
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
        $csp = $this->get('/')->headers->get('Content-Security-Policy');

        $this->assertNotNull($csp);
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9+\\/=]+'/", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
        $this->assertStringContainsString("img-src 'self' data: blob: https: http:", $csp);
        $this->assertStringContainsString("frame-src 'self' https: http:", $csp);
        $this->assertStringContainsString("connect-src 'self' https: http: wss: ws:", $csp);
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
        $this->assertStringContainsString("'unsafe-eval'", $csp);
    }

    public function test_csp_style_src_uses_nonce_instead_of_unsafe_inline(): void
    {
        $csp = (string) $this->get('/')->headers->get('Content-Security-Policy');

        $this->assertMatchesRegularExpression("/style-src 'self' 'nonce-[A-Za-z0-9+\\/=]+'(;|$)/", $csp);
        $this->assertStringContainsString("style-src-attr 'unsafe-inline'", $csp);
    }

    public function test_app_shell_exposes_csp_nonce_meta_matching_header(): void
    {
        User::factory()->create();
        $response = $this->get('/login');
        preg_match("/'nonce-([^']+)'/", (string) $response->headers->get('Content-Security-Policy'), $m);

        $this->assertNotEmpty($m[1] ?? null);
        $response->assertSee('<meta name="csp-nonce" content="'.$m[1].'">', false);
    }
}
