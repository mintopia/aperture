<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Kea;

use App\Enums\Integration;
use App\Models\DhcpLease;
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Services\Interfaces\IpMacResolverInterface;
use App\Services\Kea\KeaIpMacResolver;
use App\Services\ValueObjects\IpMacEntry;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class KeaIpMacResolverTest extends TestCase
{
    use LazilyRefreshDatabase;

    private KeaIpMacResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new KeaIpMacResolver;
    }

    public function test_implements_ip_mac_resolver_interface(): void
    {
        $this->assertInstanceOf(IpMacResolverInterface::class, new KeaIpMacResolver);
    }

    public function test_get_ip_mac_table_returns_empty_collection(): void
    {
        $table = $this->resolver->getIpMacTable();

        $this->assertInstanceOf(Collection::class, $table);
        $this->assertTrue($table->isEmpty());
    }

    public function test_includes_active_kea_lease_with_mac(): void
    {
        $ip = IpAddress::factory()->create(['address' => '10.0.0.5']);
        $mac = MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:01']);
        DhcpLease::factory()->create([
            'integration' => Integration::Kea->value,
            'ip_address_id' => $ip->id,
            'mac_address_id' => $mac->id,
            'expires_at' => now()->addHour(),
        ]);

        $table = $this->resolver->getIpMacTable();

        $this->assertCount(1, $table);
        $this->assertSame('10.0.0.5', $table->first()->ip);
        $this->assertSame('AA:BB:CC:DD:EE:01', $table->first()->mac);
    }

    public function test_excludes_lease_without_mac(): void
    {
        DhcpLease::factory()->create([
            'integration' => Integration::Kea->value,
            'mac_address_id' => null,
            'expires_at' => now()->addHour(),
        ]);

        $this->assertCount(0, $this->resolver->getIpMacTable());
    }

    public function test_includes_active_kea_ipv6_lease_with_mac(): void
    {
        $ip = IpAddress::factory()->create(['address' => '2001:db8::1']);
        $mac = MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:02']);
        DhcpLease::factory()->create([
            'integration' => Integration::Kea->value,
            'ip_address_id' => $ip->id,
            'mac_address_id' => $mac->id,
            'expires_at' => now()->addHour(),
        ]);

        $table = $this->resolver->getIpMacTable();

        $this->assertCount(1, $table);
        $entry = $table->first();
        $this->assertInstanceOf(IpMacEntry::class, $entry);
        $this->assertSame('2001:db8::1', $entry->ip);
        $this->assertSame('AA:BB:CC:DD:EE:02', $entry->mac);
    }

    public function test_excludes_ipv6_lease_without_mac(): void
    {
        $ip = IpAddress::factory()->create(['address' => '2001:db8::2']);
        DhcpLease::factory()->create([
            'integration' => Integration::Kea->value,
            'ip_address_id' => $ip->id,
            'mac_address_id' => null,
            'expires_at' => now()->addHour(),
        ]);

        $this->assertCount(0, $this->resolver->getIpMacTable());
    }

    public function test_excludes_expired_lease(): void
    {
        DhcpLease::factory()->create([
            'integration' => Integration::Kea->value,
            'expires_at' => now()->subHour(),
        ]);

        $this->assertCount(0, $this->resolver->getIpMacTable());
    }

    public function test_includes_lease_with_null_expiry(): void
    {
        DhcpLease::factory()->create([
            'integration' => Integration::Kea->value,
            'expires_at' => null,
        ]);

        $this->assertCount(1, $this->resolver->getIpMacTable());
    }

    public function test_excludes_lease_from_other_integration(): void
    {
        DhcpLease::factory()->create([
            'integration' => 'librenms',
            'expires_at' => now()->addHour(),
        ]);

        $this->assertCount(0, $this->resolver->getIpMacTable());
    }
}
