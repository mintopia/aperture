<?php

namespace Tests\Unit\Services;

use App\Services\LibreNms\LibreNmsService;
use App\Services\ValueObjects\ResolvedPort;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Collection;
use Tests\Support\Fake;
use Tests\TestCase;
use Throwable;

class LibreNmsServiceTest extends TestCase
{
    /**
     * @param  list<PromiseInterface|Throwable>  $responses
     */
    protected function createServiceWithMockClient(array $responses): LibreNmsService
    {
        Fake::sequence($responses);

        return new LibreNmsService('http://librenms.test', 'api-token');
    }

    public function test_get_forwarding_database_returns_collection(): void
    {
        $responseBody = json_encode([
            'fdb' => [
                ['mac_address' => 'aa:bb:cc:dd:ee:ff', 'port_id' => '1', 'vlan_id' => 100],
                ['mac_address' => '11:22:33:44:55:66', 'port_id' => '2', 'vlan_id' => 200],
            ],
        ]);

        $service = $this->createServiceWithMockClient([
            Fake::response(200, [], $responseBody),
        ]);

        $result = $service->getForwardingDatabase();
        $this->assertInstanceOf(Collection::class, $result);
        $this->assertCount(2, $result);
        $this->assertEquals('aa:bb:cc:dd:ee:ff', $result[0]->mac);
        $this->assertEquals('1', $result[0]->port);
        $this->assertEquals(100, $result[0]->vlan);
    }

    public function test_get_ip_mac_table_returns_collection(): void
    {
        $responseBody = json_encode([
            'arp' => [
                ['ipv4_address' => '10.0.0.1', 'mac_address' => 'aa:bb:cc:dd:ee:ff'],
                ['ipv4_address' => '10.0.0.2', 'mac_address' => '11:22:33:44:55:66'],
            ],
        ]);

        $service = $this->createServiceWithMockClient([
            Fake::response(200, [], $responseBody),
        ]);

        $result = $service->getIpMacTable();
        $this->assertInstanceOf(Collection::class, $result);
        $this->assertCount(2, $result);
        $this->assertEquals('10.0.0.1', $result[0]->ip);
        $this->assertEquals('aa:bb:cc:dd:ee:ff', $result[0]->mac);
    }

    public function test_resolve_ip_to_port_returns_array_when_found(): void
    {
        $arpResponse = json_encode([
            'arp' => [
                ['ipv4_address' => '10.0.0.1', 'mac_address' => 'aa:bb:cc:dd:ee:ff'],
            ],
        ]);
        $fdbResponse = json_encode([
            'fdb' => [
                ['mac_address' => 'aa:bb:cc:dd:ee:ff', 'port_id' => '42', 'vlan_id' => 100],
            ],
        ]);

        $service = $this->createServiceWithMockClient([
            Fake::response(200, [], $arpResponse),
            Fake::response(200, [], $fdbResponse),
        ]);

        $result = $service->resolveIpToPort('10.0.0.1');
        $this->assertInstanceOf(ResolvedPort::class, $result);
        $this->assertEquals('10.0.0.1', $result->ip);
        $this->assertEquals('aa:bb:cc:dd:ee:ff', $result->mac);
        $this->assertEquals('42', $result->port);
    }

    public function test_resolve_ip_to_port_returns_null_when_arp_not_found(): void
    {
        $arpResponse = json_encode(['arp' => []]);

        $service = $this->createServiceWithMockClient([
            Fake::response(200, [], $arpResponse),
        ]);

        $result = $service->resolveIpToPort('10.0.0.99');
        $this->assertNull($result);
    }

    public function test_resolve_ip_to_port_returns_null_when_fdb_not_found(): void
    {
        $arpResponse = json_encode([
            'arp' => [
                ['ipv4_address' => '10.0.0.1', 'mac_address' => 'aa:bb:cc:dd:ee:ff'],
            ],
        ]);
        $fdbResponse = json_encode(['fdb' => []]);

        $service = $this->createServiceWithMockClient([
            Fake::response(200, [], $arpResponse),
            Fake::response(200, [], $fdbResponse),
        ]);

        $result = $service->resolveIpToPort('10.0.0.1');
        $this->assertNull($result);
    }

    public function test_get_device_list_returns_collection(): void
    {
        $responseBody = json_encode([
            'devices' => [
                ['hostname' => 'switch-1', 'ip' => '10.0.0.1', 'type' => 'network'],
            ],
        ]);

        $service = $this->createServiceWithMockClient([
            Fake::response(200, [], $responseBody),
        ]);

        $result = $service->getDeviceList();
        $this->assertInstanceOf(Collection::class, $result);
        $this->assertCount(1, $result);
        $this->assertEquals('switch-1', $result[0]->hostname);
    }

    public function test_get_ipv6_neighbors_filters_ipv6_only(): void
    {
        $responseBody = json_encode([
            'arp' => [
                ['ipv4_address' => '10.0.0.1', 'mac_address' => 'aa:bb:cc:dd:ee:ff'],
                ['ipv4_address' => 'fe80::1', 'mac_address' => '11:22:33:44:55:66'],
                ['ipv4_address' => '2001:db8::1', 'mac_address' => 'aa:bb:cc:11:22:33'],
            ],
        ]);

        $service = $this->createServiceWithMockClient([
            Fake::response(200, [], $responseBody),
        ]);

        $result = $service->getIpv6Neighbors();
        $this->assertInstanceOf(Collection::class, $result);
        $this->assertCount(2, $result);
        $this->assertSame('fe80::1', $result->first()->ip);
        $this->assertSame('11:22:33:44:55:66', $result->first()->mac);
        $this->assertSame('2001:db8::1', $result->values()[1]->ip);
        $this->assertSame('aa:bb:cc:11:22:33', $result->values()[1]->mac);
    }

    public function test_get_ipv6_neighbors_returns_empty_when_no_ipv6(): void
    {
        $responseBody = json_encode([
            'arp' => [
                ['ipv4_address' => '10.0.0.1', 'mac_address' => 'aa:bb:cc:dd:ee:ff'],
                ['ipv4_address' => '192.168.1.1', 'mac_address' => '11:22:33:44:55:66'],
            ],
        ]);

        $service = $this->createServiceWithMockClient([
            Fake::response(200, [], $responseBody),
        ]);

        $result = $service->getIpv6Neighbors();
        $this->assertInstanceOf(Collection::class, $result);
        $this->assertCount(0, $result);
    }

    public function test_get_ipv6_neighbors_handles_empty_arp(): void
    {
        $responseBody = json_encode(['arp' => []]);

        $service = $this->createServiceWithMockClient([
            Fake::response(200, [], $responseBody),
        ]);

        $result = $service->getIpv6Neighbors();
        $this->assertInstanceOf(Collection::class, $result);
        $this->assertCount(0, $result);
    }

    public function test_get_port_detail_returns_port_data(): void
    {
        $portResponse = json_encode([
            'port' => [
                'device_id' => 5,
                'ifName' => 'Gi0/1',
                'ifOperStatus' => 'up',
                'ifAdminStatus' => 'up',
                'ifSpeed' => 1000,
            ],
        ]);
        $deviceResponse = json_encode([
            'devices' => [
                ['hostname' => 'switch01'],
            ],
        ]);

        $service = $this->createServiceWithMockClient([
            Fake::response(200, [], $portResponse),
            Fake::response(200, [], $deviceResponse),
        ]);

        $result = $service->getPortDetail('42');
        $this->assertNotNull($result);
        $this->assertSame('switch01', $result->hostname);
        $this->assertSame('Gi0/1', $result->interface);
        $this->assertSame('up', $result->status);
        $this->assertSame('up', $result->adminStatus);
        $this->assertSame(1000, $result->speed);
    }

    public function test_get_port_detail_returns_null_when_port_missing(): void
    {
        $portResponse = json_encode(['port' => null]);

        $service = $this->createServiceWithMockClient([
            Fake::response(200, [], $portResponse),
        ]);

        $result = $service->getPortDetail('999');
        $this->assertNull($result);
    }
}
