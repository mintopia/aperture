<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dhcp;

use App\Enums\AddressFamily;
use App\Services\OpnSense\OpnSenseClient;
use App\Services\OpnSense\OpnSenseDhcpService;
use App\Services\ValueObjects\DhcpRange;
use ReflectionMethod;
use Tests\TestCase;

class OpnSenseDhcpServiceRangeParsingTest extends TestCase
{
    private function service(array $rangeFieldMap = []): OpnSenseDhcpService
    {
        $map = $rangeFieldMap + [
            'interface' => 'interface',
            'subnet' => 'subnet',
            'range_from' => 'range_from',
            'range_to' => 'range_to',
            'gateway' => 'gateway',
            'description' => 'description',
            'prefix' => 'prefix',
        ];

        return new OpnSenseDhcpService(
            OpnSenseClient::fromConfig(['endpoint' => 'http://opnsense.test', 'key' => 'key', 'secret' => 'secret'])->request(),
            rangeFieldMap: $map,
        );
    }

    private function invokePrivate(OpnSenseDhcpService $service, string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod($service, $method))->invoke($service, ...$args);
    }

    public function test_kea_pools_split_into_range_bounds(): void
    {
        $service = $this->service(['pools' => 'pools']);

        $this->assertSame(
            ['10.0.0.10', '10.0.0.50'],
            $this->invokePrivate($service, 'parseKeaPools', ['pools' => '10.0.0.10 - 10.0.0.50'], null, null),
        );
    }

    public function test_kea_pools_do_not_override_explicit_bounds(): void
    {
        $service = $this->service(['pools' => 'pools']);

        $this->assertSame(
            ['10.0.0.1', '10.0.0.2'],
            $this->invokePrivate($service, 'parseKeaPools', ['pools' => '10.0.0.10 - 10.0.0.50'], '10.0.0.1', '10.0.0.2'),
        );
    }

    public function test_kea_pools_ignore_malformed_value(): void
    {
        $service = $this->service(['pools' => 'pools']);

        $this->assertSame([null, null], $this->invokePrivate($service, 'parseKeaPools', ['pools' => 'garbage'], null, null));
    }

    public function test_dnsmasq_ipv4_mask_derives_subnet(): void
    {
        $service = $this->service(['subnet_mask' => 'subnet_mask']);

        $this->assertSame(
            '192.168.1.0/24',
            $this->invokePrivate($service, 'parseDnsmasqIpv4Mask', ['subnet_mask' => '255.255.255.0'], '192.168.1.100'),
        );
    }

    public function test_dnsmasq_ipv4_mask_returns_null_without_mask_or_range(): void
    {
        $service = $this->service(['subnet_mask' => 'subnet_mask']);

        $this->assertNull($this->invokePrivate($service, 'parseDnsmasqIpv4Mask', ['subnet_mask' => ''], '192.168.1.100'));
        $this->assertNull($this->invokePrivate($service, 'parseDnsmasqIpv4Mask', ['subnet_mask' => '255.255.255.0'], null));
        $this->assertNull($this->invokePrivate($service, 'parseDnsmasqIpv4Mask', [], '192.168.1.100'));
    }

    public function test_dnsmasq_ipv6_prefix_length_is_joined_to_range_start(): void
    {
        $service = $this->service();

        $this->assertSame('fd00::1/64', $this->invokePrivate($service, 'parseDnsmasqIpv6PrefixLength', '64', 'fd00::1'));
    }

    public function test_dnsmasq_ipv6_prefix_length_leaves_other_values_untouched(): void
    {
        $service = $this->service();

        $this->assertSame('fd00::/64', $this->invokePrivate($service, 'parseDnsmasqIpv6PrefixLength', 'fd00::/64', 'fd00::1'));
        $this->assertSame('24', $this->invokePrivate($service, 'parseDnsmasqIpv6PrefixLength', '24', '10.0.0.1'));
        $this->assertNull($this->invokePrivate($service, 'parseDnsmasqIpv6PrefixLength', null, 'fd00::1'));
    }

    public function test_build_range_from_row_combines_the_format_parsers(): void
    {
        $service = $this->service(['pools' => 'pools', 'subnet_mask' => 'subnet_mask']);

        /** @var DhcpRange $range */
        $range = $this->invokePrivate($service, 'buildRangeFromRow', [
            'interface' => 'lan',
            'pools' => '192.168.1.10 - 192.168.1.20',
            'subnet_mask' => '255.255.255.0',
        ]);

        $this->assertSame('192.168.1.0/24', $range->subnet);
        $this->assertSame('192.168.1.10', $range->rangeFrom);
        $this->assertSame('192.168.1.20', $range->rangeTo);
        $this->assertSame(AddressFamily::IPv4, $range->type);
    }
}
