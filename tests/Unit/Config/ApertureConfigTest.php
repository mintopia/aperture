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

    public function test_cisco_hostname_defaults_to_null(): void
    {
        $this->assertNull(config('aperture.cisco.hostname'));
    }

    // Integration configs removed — now stored in IntegrationConfig DB model

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
            'enabled' => false,
            'host' => '127.0.0.1',
            'port' => 8022,
            'api_key' => null,
            'keepalive_seconds' => 300,
            'idle_timeout_seconds' => 600,
            'sweep_interval_seconds' => 60,
            'command_timeout_seconds' => 30,
            'read_timeout_seconds' => 5,
        ]);

        $config = config('aperture.ssh_proxy');
        $this->assertIsArray($config);
        $this->assertFalse($config['enabled']);
        $this->assertSame('127.0.0.1', $config['host']);
        $this->assertSame(8022, $config['port']);
        $this->assertNull($config['api_key']);
        $this->assertSame(300, $config['keepalive_seconds']);
        $this->assertSame(600, $config['idle_timeout_seconds']);
        $this->assertSame(60, $config['sweep_interval_seconds']);
        $this->assertSame(30, $config['command_timeout_seconds']);
        $this->assertSame(5, $config['read_timeout_seconds']);
    }

    public function test_ssh_proxy_port_is_integer(): void
    {
        $this->assertIsInt(config('aperture.ssh_proxy.port'));
    }

    public function test_ssh_proxy_timeouts_are_integers(): void
    {
        $this->assertIsInt(config('aperture.ssh_proxy.keepalive_seconds'));
        $this->assertIsInt(config('aperture.ssh_proxy.idle_timeout_seconds'));
        $this->assertIsInt(config('aperture.ssh_proxy.sweep_interval_seconds'));
        $this->assertIsInt(config('aperture.ssh_proxy.command_timeout_seconds'));
        $this->assertIsInt(config('aperture.ssh_proxy.read_timeout_seconds'));
    }
}
