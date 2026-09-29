<?php

declare(strict_types=1);

namespace Tests\Unit\Services\VyOs;

use App\Services\VyOs\VyOsClient;
use App\Services\VyOs\VyOsDhcpService;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Support\DhcpFetchStatusArray;
use Tests\TestCase;

class VyOsDhcpServiceFetchStatusTest extends TestCase
{
    private VyOsClient&MockInterface $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = Mockery::mock(VyOsClient::class);
    }

    private function allHealthy(): array
    {
        return ['ipv4' => true, 'ipv6' => true, 'ipv4_ranges' => true, 'ipv6_ranges' => true];
    }

    public function test_healthy_fetches_keep_all_true_including_empty_output(): void
    {
        $this->client->shouldReceive('showText')->andReturn('');
        $this->client->shouldReceive('retrieve')->andReturn([]);
        $service = new VyOsDhcpService($this->client);

        $snapshot = $service->snapshot();

        $this->assertSame($this->allHealthy(), DhcpFetchStatusArray::of($snapshot));
    }

    public function test_each_lease_family_fails_independently(): void
    {
        $this->client->shouldReceive('showText')->with(['dhcp', 'server', 'leases'])->andReturn('');
        $this->client->shouldReceive('showText')->with(['dhcpv6', 'server', 'leases'])->andThrow(new RuntimeException('down'));
        $this->client->shouldReceive('retrieve')->andReturn([]);
        $service = new VyOsDhcpService($this->client);

        $snapshot = $service->snapshot();

        $this->assertSame(['ipv4' => true, 'ipv6' => false, 'ipv4_ranges' => true, 'ipv6_ranges' => true], DhcpFetchStatusArray::of($snapshot));
    }

    public function test_unparseable_lease_output_marks_family_failed(): void
    {
        $this->client->shouldReceive('showText')->with(['dhcp', 'server', 'leases'])->andReturn('<html>bad gateway</html>');
        $this->client->shouldReceive('showText')->with(['dhcpv6', 'server', 'leases'])->andReturn('');
        $this->client->shouldReceive('retrieve')->andReturn([]);
        $service = new VyOsDhcpService($this->client);

        $snapshot = $service->snapshot();

        $this->assertFalse(DhcpFetchStatusArray::of($snapshot)['ipv4']);
        $this->assertTrue(DhcpFetchStatusArray::of($snapshot)['ipv6']);
    }

    public function test_range_failures_are_tracked_per_family(): void
    {
        $this->client->shouldReceive('showText')->andReturn('');
        $this->client->shouldReceive('retrieve')->with(['service', 'dhcp-server', 'shared-network-name'])->andThrow(new RuntimeException('down'));
        $this->client->shouldReceive('retrieve')->with(['service', 'dhcpv6-server', 'shared-network-name'])->andReturn([]);
        $service = new VyOsDhcpService($this->client);

        $snapshot = $service->snapshot();

        $this->assertSame(['ipv4' => true, 'ipv6' => true, 'ipv4_ranges' => false, 'ipv6_ranges' => true], DhcpFetchStatusArray::of($snapshot));
    }
}
