<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Setting;
use App\Services\NetworkRangeService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
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

        Setting::set('network.managed_ranges_v4', 'Managed IPv4 Ranges', json_encode([]));

        $this->assertTrue($this->service->isManaged('10.0.0.2'));
    }

    #[Test]
    public function it_handles_ipv4_range_boundaries(): void
    {
        Setting::set('network.managed_ranges_v4', 'Managed IPv4 Ranges', json_encode(['192.168.1.0/24']));

        $this->assertTrue($this->service->isManaged('192.168.1.0'));
        $this->assertTrue($this->service->isManaged('192.168.1.255'));
        $this->assertFalse($this->service->isManaged('192.168.0.255'));
        $this->assertFalse($this->service->isManaged('192.168.2.0'));
    }

    #[Test]
    public function it_handles_ipv4_slash_zero(): void
    {
        Setting::set('network.managed_ranges_v4', 'Managed IPv4 Ranges', json_encode(['0.0.0.0/0']));

        $this->assertTrue($this->service->isManaged('8.8.8.8'));
    }

    #[Test]
    public function it_handles_ipv6_slash_64_boundaries(): void
    {
        Setting::set('network.managed_ranges_v6', 'Managed IPv6 Ranges', json_encode(['2001:db8:1:2::/64']));

        $this->assertTrue($this->service->isManaged('2001:db8:1:2::'));
        $this->assertTrue($this->service->isManaged('2001:db8:1:2:ffff:ffff:ffff:ffff'));
        $this->assertFalse($this->service->isManaged('2001:db8:1:3::'));
        $this->assertFalse($this->service->isManaged('2001:db8:1:1:ffff:ffff:ffff:ffff'));
    }

    #[Test]
    public function it_handles_ipv6_slash_zero(): void
    {
        Setting::set('network.managed_ranges_v6', 'Managed IPv6 Ranges', json_encode(['::/0']));

        $this->assertTrue($this->service->isManaged('2001:db8::1'));
    }

    #[Test]
    public function it_ignores_malformed_and_wrong_family_ranges(): void
    {
        Setting::set('network.managed_ranges_v4', 'Managed IPv4 Ranges', json_encode(['garbage', 'fd00::/8', '10.0.0.0/99']));

        $this->assertFalse($this->service->isManaged('10.0.0.1'));
    }

    #[Test]
    public function it_rejects_ipv4_when_only_ipv6_range_configured(): void
    {
        Setting::set('network.managed_ranges_v4', 'Managed IPv4 Ranges', json_encode(['10.0.0.0/8']));
        Setting::set('network.managed_ranges_v6', 'Managed IPv6 Ranges', json_encode(['fd00::/8']));

        $this->assertFalse($this->service->isManaged('fe80::1'));
        $this->assertFalse($this->service->isManaged('11.0.0.1'));
    }

    /**
     * @return array<string, array{string, 4|6, bool}>
     */
    public static function cidrProvider(): array
    {
        return [
            'v4 valid' => ['10.0.0.0/8', 4, true],
            'v4 slash 0' => ['0.0.0.0/0', 4, true],
            'v4 slash 32' => ['10.0.0.1/32', 4, true],
            'v4 prefix too large' => ['10.0.0.0/33', 4, false],
            'v4 missing prefix' => ['10.0.0.0', 4, false],
            'v4 non-numeric prefix' => ['10.0.0.0/x', 4, false],
            'v4 bad ip' => ['999.0.0.0/8', 4, false],
            'v4 given v6 address' => ['fd00::/8', 4, false],
            'v6 valid' => ['fc00::/7', 6, true],
            'v6 slash 128' => ['fd00::1/128', 6, true],
            'v6 prefix too large' => ['fd00::/129', 6, false],
            'v6 given v4 address' => ['10.0.0.0/8', 6, false],
            'v6 garbage' => ['nope/64', 6, false],
        ];
    }

    #[Test]
    #[DataProvider('cidrProvider')]
    public function it_validates_cidr(string $cidr, int $family, bool $expected): void
    {
        $this->assertSame($expected, NetworkRangeService::isValidCidr($cidr, $family));
    }
}
