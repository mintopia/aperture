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
}
