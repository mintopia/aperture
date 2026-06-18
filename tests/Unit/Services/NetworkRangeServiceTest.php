<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Setting;
use App\Services\NetworkRangeService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NetworkRangeServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private NetworkRangeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new NetworkRangeService;
    }

    #[Test]
    public function it_allows_ipv4_within_configured_range(): void
    {
        Setting::set('network.managed_ranges_v4', 'Managed IPv4 Ranges', json_encode(['10.0.0.0/8']));

        $this->assertTrue($this->service->isManaged('10.0.0.1'));
        $this->assertTrue($this->service->isManaged('10.255.255.255'));
    }

    #[Test]
    public function it_rejects_ipv4_outside_configured_range(): void
    {
        Setting::set('network.managed_ranges_v4', 'Managed IPv4 Ranges', json_encode(['10.0.0.0/8']));

        $this->assertFalse($this->service->isManaged('192.168.1.1'));
        $this->assertFalse($this->service->isManaged('172.16.0.1'));
    }

    #[Test]
    public function it_allows_ipv6_within_configured_range(): void
    {
        Setting::set('network.managed_ranges_v6', 'Managed IPv6 Ranges', json_encode(['fc00::/7']));

        $this->assertTrue($this->service->isManaged('fd00::1'));
        $this->assertTrue($this->service->isManaged('fc00::abcd'));
    }

    #[Test]
    public function it_rejects_ipv6_outside_configured_range(): void
    {
        Setting::set('network.managed_ranges_v6', 'Managed IPv6 Ranges', json_encode(['fc00::/7']));

        $this->assertFalse($this->service->isManaged('2001:db8::1'));
    }

    #[Test]
    public function it_matches_multiple_ranges(): void
    {
        Setting::set('network.managed_ranges_v4', 'Managed IPv4 Ranges', json_encode([
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16',
        ]));

        $this->assertTrue($this->service->isManaged('10.1.2.3'));
        $this->assertTrue($this->service->isManaged('172.20.0.1'));
        $this->assertTrue($this->service->isManaged('192.168.1.1'));
        $this->assertFalse($this->service->isManaged('8.8.8.8'));
    }

    #[Test]
    public function it_denies_all_when_ranges_are_empty(): void
    {
        Setting::set('network.managed_ranges_v4', 'Managed IPv4 Ranges', json_encode([]));
        Setting::set('network.managed_ranges_v6', 'Managed IPv6 Ranges', json_encode([]));

        $this->assertFalse($this->service->isManaged('10.0.0.1'));
        $this->assertFalse($this->service->isManaged('fd00::1'));
    }

    #[Test]
    public function it_defaults_to_allow_all_when_no_settings_exist(): void
    {
        // No Setting::set calls — settings don't exist in DB

        $this->assertTrue($this->service->isManaged('10.0.0.1'));
        $this->assertTrue($this->service->isManaged('192.168.1.1'));
        $this->assertTrue($this->service->isManaged('8.8.8.8'));
        $this->assertTrue($this->service->isManaged('fd00::1'));
        $this->assertTrue($this->service->isManaged('2001:db8::1'));
    }

    #[Test]
    public function it_handles_slash_32_single_host(): void
    {
        Setting::set('network.managed_ranges_v4', 'Managed IPv4 Ranges', json_encode(['10.0.0.1/32']));

        $this->assertTrue($this->service->isManaged('10.0.0.1'));
        $this->assertFalse($this->service->isManaged('10.0.0.2'));
    }

    #[Test]
    public function it_handles_slash_128_single_host(): void
    {
        Setting::set('network.managed_ranges_v6', 'Managed IPv6 Ranges', json_encode(['fd00::1/128']));

        $this->assertTrue($this->service->isManaged('fd00::1'));
        $this->assertFalse($this->service->isManaged('fd00::2'));
    }

    #[Test]
    public function it_returns_false_for_invalid_ip(): void
    {
        $this->assertFalse($this->service->isManaged('not-an-ip'));
        $this->assertFalse($this->service->isManaged(''));
    }

    #[Test]
    public function it_caches_ranges_within_same_instance(): void
    {
        Setting::set('network.managed_ranges_v4', 'Managed IPv4 Ranges', json_encode(['10.0.0.0/8']));

        $this->assertTrue($this->service->isManaged('10.0.0.1'));

        // Change setting — same instance should use cached value
        Setting::set('network.managed_ranges_v4', 'Managed IPv4 Ranges', json_encode([]));

        $this->assertTrue($this->service->isManaged('10.0.0.2'));
    }
}
