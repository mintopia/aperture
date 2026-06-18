<?php

declare(strict_types=1);

namespace Tests\Feature\Middleware;

use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
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
}
