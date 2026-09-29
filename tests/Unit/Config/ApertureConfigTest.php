<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use Tests\TestCase;

class ApertureConfigTest extends TestCase
{
    public function test_cisco_hostname_defaults_to_null(): void
    {
        $this->assertNull(config('aperture.cisco.hostname'));
    }

    public function test_opnsense_config_removed(): void
    {
        $this->assertNull(config('aperture.opnsense'));
    }

    public function test_ntopng_config_removed(): void
    {
        $this->assertNull(config('aperture.ntopng'));
    }

    public function test_dhcp_config_removed(): void
    {
        $this->assertNull(config('aperture.dhcp'));
    }

    public function test_pihole_config_removed(): void
    {
        $this->assertNull(config('aperture.pihole'));
    }

    public function test_auto_allow_config_removed(): void
    {
        $this->assertNull(config('aperture.auto_allow'));
    }

    public function test_ipv6_config_removed(): void
    {
        $this->assertNull(config('aperture.ipv6'));
    }

    public function test_lnms_config_removed(): void
    {
        $this->assertNull(config('aperture.lnms'));
    }

    public function test_ssh_proxy_config_has_defaults(): void
    {
        config()->set('aperture.ssh_proxy', [
            'host' => '127.0.0.1',
            'port' => 8022,
            'api_key' => null,
        ]);

        $config = config('aperture.ssh_proxy');
        $this->assertIsArray($config);
        $this->assertArrayNotHasKey('enabled', $config);
        $this->assertSame('127.0.0.1', $config['host']);
        $this->assertSame(8022, $config['port']);
        $this->assertNull($config['api_key']);
    }

    public function test_ssh_proxy_port_is_integer(): void
    {
        $this->assertIsInt(config('aperture.ssh_proxy.port'));
    }
}
