<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\IpAddress;
use App\Models\Setting;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CaptivePortalApiControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    #[Test]
    public function returns_captive_true_for_unknown_ip(): void
    {
        $response = $this->get('/api/captive-portal');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/captive+json');
        $response->assertHeader('Cache-Control', 'no-store, private');
        $response->assertJson(['captive' => true]);
    }

    #[Test]
    public function returns_captive_true_for_ip_without_internet(): void
    {
        IpAddress::factory()->create([
            'address' => '127.0.0.1',
            'internet_enabled' => false,
        ]);

        $response = $this->get('/api/captive-portal');

        $response->assertOk();
        $response->assertJson(['captive' => true]);
    }

    #[Test]
    public function returns_captive_false_for_ip_with_internet(): void
    {
        IpAddress::factory()->create([
            'address' => '127.0.0.1',
            'internet_enabled' => true,
        ]);

        $response = $this->get('/api/captive-portal');

        $response->assertOk();
        $response->assertJson(['captive' => false]);
    }

    #[Test]
    public function finds_stored_ipv6_address_when_client_ip_presents_uppercase(): void
    {
        IpAddress::factory()->create([
            'address' => '2001:db8::1',
            'internet_enabled' => true,
        ]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '2001:DB8::1'])
            ->get('/api/captive-portal');

        $response->assertOk();
        $response->assertJson(['captive' => false]);
    }

    #[Test]
    public function includes_user_portal_url_when_configured(): void
    {
        Setting::set('captive_portal_api.user_portal_url', 'User Portal URL', 'https://portal.example.com');

        $response = $this->get('/api/captive-portal');

        $response->assertOk();
        $response->assertJson([
            'captive' => true,
            'user-portal-url' => 'https://portal.example.com',
        ]);
    }

    #[Test]
    public function includes_venue_info_url_when_configured(): void
    {
        Setting::set('captive_portal_api.venue_info_url', 'Venue Info URL', 'https://venue.example.com');

        $response = $this->get('/api/captive-portal');

        $response->assertOk();
        $response->assertJson([
            'captive' => true,
            'venue-info-url' => 'https://venue.example.com',
        ]);
    }

    #[Test]
    public function includes_can_extend_session_when_configured(): void
    {
        Setting::set('captive_portal_api.can_extend_session', 'Can Extend Session', '1');

        $response = $this->get('/api/captive-portal');

        $response->assertOk();
        $response->assertJson([
            'captive' => true,
            'can-extend-session' => true,
        ]);
    }

    #[Test]
    public function excludes_optional_fields_when_not_configured(): void
    {
        $response = $this->get('/api/captive-portal');

        $response->assertOk();

        $data = $response->json();
        $this->assertArrayHasKey('captive', $data);
        $this->assertArrayNotHasKey('user-portal-url', $data);
        $this->assertArrayNotHasKey('venue-info-url', $data);
        $this->assertArrayNotHasKey('can-extend-session', $data);
    }

    #[Test]
    public function excludes_empty_urls(): void
    {
        Setting::set('captive_portal_api.user_portal_url', 'User Portal URL', '');
        Setting::set('captive_portal_api.venue_info_url', 'Venue Info URL', '');

        $response = $this->get('/api/captive-portal');

        $data = $response->json();
        $this->assertArrayNotHasKey('user-portal-url', $data);
        $this->assertArrayNotHasKey('venue-info-url', $data);
    }

    #[Test]
    public function returns_correct_content_type(): void
    {
        $response = $this->get('/api/captive-portal', ['Accept' => 'application/captive+json']);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/captive+json');
    }

    #[Test]
    public function does_not_require_authentication(): void
    {
        $response = $this->get('/api/captive-portal');

        $response->assertOk();
    }

    #[Test]
    public function returns_all_configured_fields(): void
    {
        IpAddress::factory()->create([
            'address' => '127.0.0.1',
            'internet_enabled' => false,
        ]);

        Setting::set('captive_portal_api.user_portal_url', 'User Portal URL', 'https://portal.example.com');
        Setting::set('captive_portal_api.venue_info_url', 'Venue Info URL', 'https://venue.example.com');
        Setting::set('captive_portal_api.can_extend_session', 'Can Extend Session', '1');

        $response = $this->get('/api/captive-portal');

        $response->assertOk();
        $response->assertExactJson([
            'captive' => true,
            'user-portal-url' => 'https://portal.example.com',
            'venue-info-url' => 'https://venue.example.com',
            'can-extend-session' => true,
        ]);
    }
}
