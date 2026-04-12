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
}
