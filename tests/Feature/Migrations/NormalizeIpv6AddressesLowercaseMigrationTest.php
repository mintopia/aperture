<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Models\SwitchConfig;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NormalizeIpv6AddressesLowercaseMigrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function runMigration(): void
    {
        $migration = require database_path('migrations/2026_06_10_000001_normalize_ipv6_addresses_lowercase.php');
        $migration->up();
    }

    private function insertIpAddress(string $address): int
    {
        return (int) DB::table('ip_addresses')->insertGetId([
            'address' => $address,
            'last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function insertRangeRecord(array $overrides = []): int
    {
        return (int) DB::table('dhcp_range_records')->insertGetId(array_merge([
            'integration' => 'cisco',
            'interface' => 'LAN6',
            'type' => 'ipv6',
            'subnet' => '',
            'range_from' => '',
            'range_to' => '',
            'prefix' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function insertSnoopingObservation(int $switchConfigId, array $overrides = []): int
    {
        return (int) DB::table('dhcp_snooping_observations')->insertGetId(array_merge([
            'switch_config_id' => $switchConfigId,
            'vlan' => 100,
            'ip' => 'fd00::1',
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'observed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    public function test_lowercases_uppercase_ipv6_addresses_and_leaves_other_rows_alone(): void
    {
        $upperId = $this->insertIpAddress('2A0F:85C1:D91:2000::50');
        $ipv4Id = $this->insertIpAddress('10.0.0.50');
        $lowerId = $this->insertIpAddress('fd00::1');

        $this->runMigration();

        $this->assertSame('2a0f:85c1:d91:2000::50', DB::table('ip_addresses')->where('id', $upperId)->value('address'));
        $this->assertSame('10.0.0.50', DB::table('ip_addresses')->where('id', $ipv4Id)->value('address'));
        $this->assertSame('fd00::1', DB::table('ip_addresses')->where('id', $lowerId)->value('address'));
    }

    public function test_skips_ip_address_whose_lowered_value_would_collide_with_an_existing_row(): void
    {
        // SQLite-origin data can hold case-variant duplicates; the migration must
        // not merge them (foreign keys reference both rows) nor crash.
        $upperId = $this->insertIpAddress('2001:DB8::1');
        $lowerId = $this->insertIpAddress('2001:db8::1');

        $this->runMigration();

        $this->assertSame('2001:DB8::1', DB::table('ip_addresses')->where('id', $upperId)->value('address'));
        $this->assertSame('2001:db8::1', DB::table('ip_addresses')->where('id', $lowerId)->value('address'));
    }

    public function test_lowercases_dhcp_range_record_prefixes(): void
    {
        $upperId = $this->insertRangeRecord(['prefix' => '2A0F:85C1:D91:2100::/64']);
        $ipv4Id = $this->insertRangeRecord([
            'type' => 'ipv4',
            'interface' => 'LAN',
            'subnet' => '10.0.0.0/24',
            'range_from' => '10.0.0.10',
            'range_to' => '10.0.0.250',
        ]);

        $this->runMigration();

        $this->assertSame('2a0f:85c1:d91:2100::/64', DB::table('dhcp_range_records')->where('id', $upperId)->value('prefix'));
        $this->assertNull(DB::table('dhcp_range_records')->where('id', $ipv4Id)->value('prefix'));
    }

    public function test_lowercases_dhcp_snooping_observation_ips(): void
    {
        $switchConfig = SwitchConfig::factory()->create();
        $upperId = $this->insertSnoopingObservation($switchConfig->id, ['ip' => '2A0F:85C1:D91:2000::99']);
        $ipv4Id = $this->insertSnoopingObservation($switchConfig->id, ['ip' => '10.0.0.99']);

        $this->runMigration();

        $this->assertSame('2a0f:85c1:d91:2000::99', DB::table('dhcp_snooping_observations')->where('id', $upperId)->value('ip'));
        $this->assertSame('10.0.0.99', DB::table('dhcp_snooping_observations')->where('id', $ipv4Id)->value('ip'));
    }

    public function test_skips_snooping_observation_whose_lowered_ip_would_violate_the_unique_key(): void
    {
        $switchConfig = SwitchConfig::factory()->create();
        $upperId = $this->insertSnoopingObservation($switchConfig->id, ['ip' => 'FD00::5']);
        $lowerId = $this->insertSnoopingObservation($switchConfig->id, ['ip' => 'fd00::5']);

        $this->runMigration();

        $this->assertSame('FD00::5', DB::table('dhcp_snooping_observations')->where('id', $upperId)->value('ip'));
        $this->assertSame('fd00::5', DB::table('dhcp_snooping_observations')->where('id', $lowerId)->value('ip'));
    }

    public function test_migration_is_idempotent(): void
    {
        $id = $this->insertIpAddress('2A0F:85C1:D91:2000::50');

        $this->runMigration();
        $this->runMigration();

        $this->assertSame('2a0f:85c1:d91:2000::50', DB::table('ip_addresses')->where('id', $id)->value('address'));
        $this->assertSame(1, DB::table('ip_addresses')->count());
    }

    public function test_down_is_a_no_op(): void
    {
        $id = $this->insertIpAddress('2A0F:85C1:D91:2000::50');

        $migration = require database_path('migrations/2026_06_10_000001_normalize_ipv6_addresses_lowercase.php');
        $migration->up();
        $migration->down();

        // Case is not recoverable; down() must not change anything.
        $this->assertSame('2a0f:85c1:d91:2000::50', DB::table('ip_addresses')->where('id', $id)->value('address'));
    }
}
