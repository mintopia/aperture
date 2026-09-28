<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Enums\Capability;
use App\Models\CapabilityAssignment;
use App\Models\IpAddress;
use App\Models\MacAddress;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class KeyDhcpLeasesOnIpAndMacMigrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const OLD_UNIQUE_INDEX = 'dhcp_leases_integration_ip_unique';

    private const NEW_UNIQUE_INDEX = 'dhcp_leases_ip_address_id_mac_address_id_unique';

    private const OLD = '2026-06-01 00:00:00';

    private const NEWEST = '2026-06-09 12:00:00';

    private function runMigration(): void
    {
        $migration = require database_path('migrations/2026_09_28_000001_key_dhcp_leases_on_ip_and_mac.php');
        $migration->up();
    }

    /**
     * migrate:fresh already applies this migration, so its new unique index
     * must be dropped before legacy-shaped duplicate rows can be seeded to
     * exercise it again.
     */
    private function dropNewUniqueIndex(): void
    {
        if (! in_array(self::NEW_UNIQUE_INDEX, Schema::getIndexListing('dhcp_leases'), true)) {
            return;
        }

        Schema::table('dhcp_leases', function (Blueprint $table): void {
            $table->dropUnique(self::NEW_UNIQUE_INDEX);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function insertLease(array $attributes): int
    {
        return (int) DB::table('dhcp_leases')->insertGetId(array_merge([
            'integration' => null,
            'hostname' => null,
            'expires_at' => null,
            'created_at' => self::OLD,
            'updated_at' => self::OLD,
        ], $attributes));
    }

    public function test_backfills_null_integration_from_active_assignment(): void
    {
        $this->dropNewUniqueIndex();

        CapabilityAssignment::assign(Capability::Dhcp, 'kea');

        $ip = IpAddress::factory()->create();
        $mac = MacAddress::factory()->create();

        $leaseId = $this->insertLease([
            'ip_address_id' => $ip->id,
            'mac_address_id' => $mac->id,
        ]);

        $this->runMigration();

        $this->assertSame('kea', DB::table('dhcp_leases')->where('id', $leaseId)->value('integration'));
    }

    public function test_leaves_integration_null_when_nothing_assigned(): void
    {
        $this->dropNewUniqueIndex();

        $ip = IpAddress::factory()->create();
        $mac = MacAddress::factory()->create();

        $leaseId = $this->insertLease([
            'ip_address_id' => $ip->id,
            'mac_address_id' => $mac->id,
        ]);

        $this->runMigration();

        $this->assertNull(DB::table('dhcp_leases')->where('id', $leaseId)->value('integration'));
    }

    public function test_dedupes_keeping_non_null_integration_over_null(): void
    {
        $this->dropNewUniqueIndex();

        $ip = IpAddress::factory()->create();
        $mac = MacAddress::factory()->create();

        $nullRowId = $this->insertLease([
            'ip_address_id' => $ip->id,
            'mac_address_id' => $mac->id,
            'integration' => null,
            'hostname' => 'null-host',
            'updated_at' => self::NEWEST,
        ]);
        $taggedRowId = $this->insertLease([
            'ip_address_id' => $ip->id,
            'mac_address_id' => $mac->id,
            'integration' => 'cisco',
            'hostname' => 'cisco-host',
            'updated_at' => self::OLD,
        ]);

        $this->runMigration();

        $this->assertSame(1, DB::table('dhcp_leases')
            ->where('ip_address_id', $ip->id)
            ->where('mac_address_id', $mac->id)
            ->count());

        $this->assertDatabaseHas('dhcp_leases', ['id' => $taggedRowId, 'hostname' => 'cisco-host']);
        $this->assertDatabaseMissing('dhcp_leases', ['id' => $nullRowId]);
    }

    public function test_dedupes_keeping_latest_updated_at_when_integration_tied(): void
    {
        $this->dropNewUniqueIndex();

        $ip = IpAddress::factory()->create();
        $mac = MacAddress::factory()->create();

        $olderId = $this->insertLease([
            'ip_address_id' => $ip->id,
            'mac_address_id' => $mac->id,
            'integration' => 'cisco',
            'hostname' => 'old-host',
            'updated_at' => self::OLD,
        ]);
        $newerId = $this->insertLease([
            'ip_address_id' => $ip->id,
            'mac_address_id' => $mac->id,
            'integration' => 'cisco',
            'hostname' => 'new-host',
            'updated_at' => self::NEWEST,
        ]);

        $this->runMigration();

        $this->assertSame(1, DB::table('dhcp_leases')
            ->where('ip_address_id', $ip->id)
            ->where('mac_address_id', $mac->id)
            ->count());

        $this->assertDatabaseHas('dhcp_leases', ['id' => $newerId, 'hostname' => 'new-host']);
        $this->assertDatabaseMissing('dhcp_leases', ['id' => $olderId]);
    }

    public function test_does_not_dedupe_rows_with_null_mac(): void
    {
        $this->dropNewUniqueIndex();

        $ip = IpAddress::factory()->create();

        $firstId = $this->insertLease(['ip_address_id' => $ip->id, 'mac_address_id' => null]);
        $secondId = $this->insertLease(['ip_address_id' => $ip->id, 'mac_address_id' => null]);

        $this->runMigration();

        $this->assertDatabaseHas('dhcp_leases', ['id' => $firstId]);
        $this->assertDatabaseHas('dhcp_leases', ['id' => $secondId]);
    }

    public function test_adds_new_unique_index_and_drops_old(): void
    {
        $this->dropNewUniqueIndex();

        $this->runMigration();

        $this->assertContains(self::NEW_UNIQUE_INDEX, Schema::getIndexListing('dhcp_leases'));
        $this->assertNotContains(self::OLD_UNIQUE_INDEX, Schema::getIndexListing('dhcp_leases'));

        $ip = IpAddress::factory()->create();
        $mac = MacAddress::factory()->create();

        DB::table('dhcp_leases')->insert([
            'ip_address_id' => $ip->id,
            'mac_address_id' => $mac->id,
            'integration' => 'cisco',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('dhcp_leases')->insert([
            'ip_address_id' => $ip->id,
            'mac_address_id' => $mac->id,
            'integration' => 'vyos',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
