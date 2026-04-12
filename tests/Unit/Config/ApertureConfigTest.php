<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use Tests\TestCase;

class ApertureConfigTest extends TestCase
{
    public function test_session_ttl_has_default_value(): void
    {
        $this->assertEquals(7200, config('aperture.session.ttl'));
    }

    public function test_dhcp_enabled_defaults_to_false(): void
    {
        $this->assertFalse(config('aperture.dhcp.enabled'));
    }

    public function test_dhcp_pool_size_defaults_to_254(): void
    {
        $this->assertEquals(254, config('aperture.dhcp.pool_size'));
    }

    public function test_pihole_enabled_defaults_to_false(): void
    {
        $this->assertFalse(config('aperture.pihole.enabled'));
    }

    public function test_pihole_endpoint_defaults_to_null(): void
    {
        $this->assertNull(config('aperture.pihole.endpoint'));
    }

    public function test_pihole_noblock_group_id_defaults_to_one(): void
    {
        $this->assertEquals(1, config('aperture.pihole.noblock_group_id'));
    }

    public function test_dns_expected_server_defaults_to_null(): void
    {
        $this->assertNull(config('aperture.dns.expected_server'));
    }

    public function test_dns_probe_domain_defaults_to_null(): void
    {
        $this->assertNull(config('aperture.dns.probe_domain'));
    }

    public function test_cisco_hostname_defaults_to_null(): void
    {
        $this->assertNull(config('aperture.cisco.hostname'));
    }

    public function test_auto_allow_enabled_defaults_to_false(): void
    {
        $this->assertFalse(config('aperture.auto_allow.enabled'));
    }

    public function test_auto_allow_oui_prefixes_has_defaults(): void
    {
        $prefixes = config('aperture.auto_allow.oui_prefixes');
        $this->assertIsArray($prefixes);
        $this->assertNotEmpty($prefixes);
        $this->assertContains('98:5F:D3', $prefixes);
        $this->assertContains('7C:ED:8D', $prefixes);
    }

    public function test_auto_allow_scan_interval_defaults_to_five(): void
    {
        $this->assertEquals(5, config('aperture.auto_allow.scan_interval'));
    }

    public function test_ipv6_detection_enabled_defaults_to_false(): void
    {
        $this->assertFalse(config('aperture.ipv6.detection_enabled'));
    }

    public function test_ipv6_detection_endpoint_defaults_to_null(): void
    {
        $this->assertNull(config('aperture.ipv6.detection_endpoint'));
    }
}
