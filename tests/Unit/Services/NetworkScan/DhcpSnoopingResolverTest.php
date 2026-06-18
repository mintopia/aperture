<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NetworkScan;

use App\Models\DhcpSnoopingObservation;
use App\Models\SwitchConfig;
use App\Services\NetworkScan\DhcpSnoopingResolver;
use App\Services\ValueObjects\ArpEntry;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class DhcpSnoopingResolverTest extends TestCase
{
    use LazilyRefreshDatabase;

    private DhcpSnoopingResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new DhcpSnoopingResolver;
    }

    public function test_returns_observed_ip_mac_mappings(): void
    {
        $switch = SwitchConfig::factory()->create();
        DhcpSnoopingObservation::factory()->create([
            'switch_config_id' => $switch->id,
            'ip' => '10.0.0.50',
            'mac' => 'AA:BB:CC:DD:EE:FF',
            'observed_at' => now(),
        ]);

        $mappings = $this->resolver->getObservedMappings();

        $this->assertCount(1, $mappings);
        $this->assertInstanceOf(ArpEntry::class, $mappings->first());
        $this->assertSame('10.0.0.50', $mappings->first()->ip);
        $this->assertSame('AA:BB:CC:DD:EE:FF', $mappings->first()->mac);
    }

    public function test_excludes_expired_observations(): void
    {
        $switch = SwitchConfig::factory()->create();
        DhcpSnoopingObservation::factory()->create([
            'switch_config_id' => $switch->id,
            'ip' => '10.0.0.50',
            'mac' => 'AA:BB:CC:DD:EE:FF',
            'expires_at' => now()->subHour(),
            'observed_at' => now()->subHours(2),
        ]);

        $mappings = $this->resolver->getObservedMappings();

        $this->assertCount(0, $mappings);
    }

    public function test_includes_observations_with_null_expiry(): void
    {
        $switch = SwitchConfig::factory()->create();
        DhcpSnoopingObservation::factory()->create([
            'switch_config_id' => $switch->id,
            'ip' => '10.0.0.50',
            'mac' => 'AA:BB:CC:DD:EE:FF',
            'expires_at' => null,
            'observed_at' => now(),
        ]);

        $mappings = $this->resolver->getObservedMappings();

        $this->assertCount(1, $mappings);
    }

    public function test_deduplicates_same_ip_mac_pair(): void
    {
        $switch1 = SwitchConfig::factory()->create();
        $switch2 = SwitchConfig::factory()->create();

        DhcpSnoopingObservation::factory()->create([
            'switch_config_id' => $switch1->id,
            'ip' => '10.0.0.50',
            'mac' => 'AA:BB:CC:DD:EE:FF',
        ]);
        DhcpSnoopingObservation::factory()->create([
            'switch_config_id' => $switch2->id,
            'ip' => '10.0.0.50',
            'mac' => 'AA:BB:CC:DD:EE:FF',
        ]);

        $mappings = $this->resolver->getObservedMappings();

        $this->assertCount(1, $mappings);
    }

    public function test_returns_empty_collection_when_no_observations(): void
    {
        $mappings = $this->resolver->getObservedMappings();

        $this->assertCount(0, $mappings);
    }
}
