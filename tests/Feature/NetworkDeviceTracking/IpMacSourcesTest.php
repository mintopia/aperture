<?php

declare(strict_types=1);

namespace Tests\Feature\NetworkDeviceTracking;

use App\Enums\Capability;
use App\Models\CapabilityAssignment;
use App\Models\DhcpSnoopingObservation;
use App\Models\IntegrationConfig;
use App\Models\SwitchConfig;
use App\Services\Interfaces\IpMacResolverInterface;
use App\Services\Interfaces\MacAddressResolverInterface;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IpMacSourcesTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function fakeKeaLease(string $ip, string $mac): void
    {
        Http::fake([
            'kea.local' => Http::sequence()
                ->push([[
                    'result' => 0,
                    'arguments' => ['leases' => [[
                        'ip-address' => $ip,
                        'hw-address' => $mac,
                        'state' => 0,
                        'cltt' => now()->getTimestamp() - 100,
                        'valid-lft' => 3600,
                    ]]],
                ]])
                ->push([['result' => 3, 'arguments' => ['leases' => []]]])
                ->whenEmpty(Http::response([['result' => 3, 'arguments' => ['leases' => []]]])),
        ]);
    }

    private function observeOnSwitch(string $ip, string $mac): void
    {
        DhcpSnoopingObservation::factory()->create([
            'switch_config_id' => SwitchConfig::factory()->create()->id,
            'ip' => $ip,
            'mac' => $mac,
            'observed_at' => now(),
        ]);
    }

    public function test_kea_ip_mac_table_is_populated_when_dhcp_is_assigned_elsewhere(): void
    {
        Queue::fake();
        CapabilityAssignment::assign(Capability::Dhcp, 'opnsense');
        CapabilityAssignment::assign(Capability::IpMac, 'kea');
        IntegrationConfig::setValue('kea', 'endpoint_v4', 'https://kea.local');
        $this->fakeKeaLease('10.0.0.5', 'aa:bb:cc:dd:ee:01');

        $table = $this->app->make(IpMacResolverInterface::class)->getIpMacTable();

        $this->assertCount(1, $table);
        $this->assertSame('10.0.0.5', $table->first()->ip);
        $this->assertSame('aa:bb:cc:dd:ee:01', $table->first()->mac);
    }

    public function test_snooping_observation_resolves_ip_when_no_other_source_knows_it(): void
    {
        $this->observeOnSwitch('10.0.0.50', 'AA:BB:CC:DD:EE:50');

        $mac = $this->app->make(MacAddressResolverInterface::class)->resolveIpToMac('10.0.0.50');

        $this->assertSame('AA:BB:CC:DD:EE:50', $mac);
    }

    public function test_ip_mac_capability_takes_precedence_over_snooping(): void
    {
        Queue::fake();
        CapabilityAssignment::assign(Capability::IpMac, 'kea');
        IntegrationConfig::setValue('kea', 'endpoint_v4', 'https://kea.local');
        $this->fakeKeaLease('10.0.0.5', 'aa:bb:cc:dd:ee:01');
        $this->observeOnSwitch('10.0.0.5', 'FF:FF:FF:FF:FF:FF');

        $mac = $this->app->make(MacAddressResolverInterface::class)->resolveIpToMac('10.0.0.5');

        $this->assertSame('AA:BB:CC:DD:EE:01', $mac);
    }
}
