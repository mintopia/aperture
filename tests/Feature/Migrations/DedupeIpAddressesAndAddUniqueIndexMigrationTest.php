<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DedupeIpAddressesAndAddUniqueIndexMigrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const UNIQUE_INDEX = 'ip_addresses_address_unique';

    private const ADDRESS = '2a0f:85c1:d91:2100:7485:ac59:8bc9:72e6';

    private const ADDRESS_UPPER = '2A0F:85C1:D91:2100:7485:AC59:8BC9:72E6';

    private const OLD = '2026-06-01 00:00:00';

    private const MID = '2026-06-05 00:00:00';

    private const NEWEST = '2026-06-09 12:00:00';

    private int $keeperId;

    private int $dupeSameCaseId;

    private int $dupeUpperCaseId;

    private User $userA;

    private User $userB;

    private MacAddress $macX;

    private MacAddress $macY;

    private function runMigration(): void
    {
        $migration = require database_path('migrations/2026_06_10_100000_dedupe_ip_addresses_and_add_unique_index.php');
        $migration->up();
    }

    /**
     * migrate:fresh now creates the unique index, so it must be dropped before
     * legacy-shaped duplicate rows can be seeded to exercise this migration.
     */
    private function dropUniqueIndex(): void
    {
        if (! in_array(self::UNIQUE_INDEX, Schema::getIndexListing('ip_addresses'), true)) {
            return;
        }

        Schema::table('ip_addresses', function (Blueprint $table): void {
            $table->dropUnique(self::UNIQUE_INDEX);
        });
    }

    /**
     * Insert via DB::table to bypass the IpAddress model's normalizing setter,
     * so case-variant duplicates (SQLite-origin data) can be seeded.
     */
    private function insertIpAddress(string $address, string $lastSeenAt, bool $internetEnabled = false): int
    {
        return (int) DB::table('ip_addresses')->insertGetId([
            'address' => $address,
            'internet_enabled' => $internetEnabled,
            'last_seen_at' => $lastSeenAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedDuplicates(): void
    {
        $this->dropUniqueIndex();

        // Keeper has the lowest id; one duplicate is byte-identical, one is a
        // case variant of the same IPv6.
        $this->keeperId = $this->insertIpAddress(self::ADDRESS, self::OLD);
        $this->dupeSameCaseId = $this->insertIpAddress(self::ADDRESS, self::NEWEST, true);
        $this->dupeUpperCaseId = $this->insertIpAddress(self::ADDRESS_UPPER, self::MID);

        $this->userA = User::factory()->create();
        $this->userB = User::factory()->create();

        // user_ip_addresses: collision (userA on keeper AND a dupe) + repoint (userB on a dupe)
        DB::table('user_ip_addresses')->insert([
            ['user_id' => $this->userA->id, 'ip_address_id' => $this->keeperId, 'last_seen_at' => self::OLD, 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $this->userA->id, 'ip_address_id' => $this->dupeSameCaseId, 'last_seen_at' => self::NEWEST, 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $this->userB->id, 'ip_address_id' => $this->dupeUpperCaseId, 'last_seen_at' => self::MID, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->macX = MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:01']);
        $this->macY = MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:02']);

        // ip_address_mac_address: collision (macX on keeper AND a dupe) + repoint (macY on a dupe)
        DB::table('ip_address_mac_address')->insert([
            ['ip_address_id' => $this->keeperId, 'mac_address_id' => $this->macX->id, 'source' => 'arp', 'last_seen_at' => self::OLD, 'created_at' => now(), 'updated_at' => now()],
            ['ip_address_id' => $this->dupeSameCaseId, 'mac_address_id' => $this->macX->id, 'source' => 'dhcp', 'last_seen_at' => self::NEWEST, 'created_at' => now(), 'updated_at' => now()],
            ['ip_address_id' => $this->dupeUpperCaseId, 'mac_address_id' => $this->macY->id, 'source' => 'dhcp', 'last_seen_at' => self::MID, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // dhcp_leases: collision on unique (integration, ip_address_id) + clean repoint
        DB::table('dhcp_leases')->insert([
            ['integration' => 'cisco', 'ip_address_id' => $this->keeperId, 'mac_address_id' => $this->macX->id, 'hostname' => 'old-host', 'expires_at' => null, 'created_at' => self::OLD, 'updated_at' => self::OLD],
            ['integration' => 'cisco', 'ip_address_id' => $this->dupeUpperCaseId, 'mac_address_id' => $this->macX->id, 'hostname' => 'new-host', 'expires_at' => null, 'created_at' => self::NEWEST, 'updated_at' => self::NEWEST],
            ['integration' => 'opnsense', 'ip_address_id' => $this->dupeSameCaseId, 'mac_address_id' => $this->macX->id, 'hostname' => 'opn-host', 'expires_at' => null, 'created_at' => self::MID, 'updated_at' => self::MID],
        ]);

        // audit_logs morphs pointing at duplicates (subject and related)
        $ipMorph = (new IpAddress)->getMorphClass();
        DB::table('audit_logs')->insert([
            [
                'action' => 'ip.created',
                'subject_type' => $ipMorph,
                'subject_id' => $this->dupeSameCaseId,
                'related_type' => null,
                'related_id' => null,
                'actor_type' => null,
                'actor_id' => null,
                'process' => 'scan_network',
                'metadata' => null,
                'created_at' => now(),
            ],
            [
                'action' => 'ip_mac.linked',
                'subject_type' => (new MacAddress)->getMorphClass(),
                'subject_id' => $this->macY->id,
                'related_type' => $ipMorph,
                'related_id' => $this->dupeUpperCaseId,
                'actor_type' => null,
                'actor_id' => null,
                'process' => 'scan_network',
                'metadata' => null,
                'created_at' => now(),
            ],
        ]);
    }

    public function test_keeps_only_the_lowest_id_row_per_address(): void
    {
        $this->seedDuplicates();

        $this->runMigration();

        $survivors = DB::table('ip_addresses')
            ->whereIn('id', [$this->keeperId, $this->dupeSameCaseId, $this->dupeUpperCaseId])
            ->pluck('id');

        $this->assertCount(1, $survivors);
        $this->assertSame($this->keeperId, (int) $survivors->first());
        $this->assertSame(1, DB::table('ip_addresses')->where('address', self::ADDRESS)->count());
        $this->assertSame(0, DB::table('ip_addresses')->where('address', self::ADDRESS_UPPER)->count());
    }

    public function test_merges_scalars_into_keeper(): void
    {
        $this->seedDuplicates();

        $this->runMigration();

        $keeper = DB::table('ip_addresses')->where('id', $this->keeperId)->first();
        $this->assertNotNull($keeper);
        // internet_enabled was true on a duplicate only: logical OR
        $this->assertTrue((bool) $keeper->internet_enabled);
        // last_seen_at: max across the group
        $this->assertTrue(Carbon::parse((string) $keeper->last_seen_at)->equalTo(Carbon::parse(self::NEWEST)));
    }

    public function test_repoints_and_merges_user_ip_addresses(): void
    {
        $this->seedDuplicates();

        $this->runMigration();

        // Collision merged: a single row for (userA, keeper) with max last_seen_at
        $userARows = DB::table('user_ip_addresses')
            ->where('user_id', $this->userA->id)
            ->where('ip_address_id', $this->keeperId)
            ->get();
        $this->assertCount(1, $userARows);
        $this->assertTrue(Carbon::parse((string) $userARows->first()->last_seen_at)->equalTo(Carbon::parse(self::NEWEST)));

        // Repointed: userB now references the keeper
        $this->assertSame(1, DB::table('user_ip_addresses')
            ->where('user_id', $this->userB->id)
            ->where('ip_address_id', $this->keeperId)
            ->count());

        // Nothing references the deleted duplicates
        $this->assertSame(0, DB::table('user_ip_addresses')
            ->whereIn('ip_address_id', [$this->dupeSameCaseId, $this->dupeUpperCaseId])
            ->count());
    }

    public function test_repoints_and_merges_ip_address_mac_address_pivots(): void
    {
        $this->seedDuplicates();

        $this->runMigration();

        // Collision merged into the keeper row, keeping max last_seen_at and the
        // earliest-created source
        $macXRows = DB::table('ip_address_mac_address')
            ->where('ip_address_id', $this->keeperId)
            ->where('mac_address_id', $this->macX->id)
            ->get();
        $this->assertCount(1, $macXRows);
        $this->assertTrue(Carbon::parse((string) $macXRows->first()->last_seen_at)->equalTo(Carbon::parse(self::NEWEST)));
        $this->assertSame('arp', $macXRows->first()->source);

        // Repointed: macY now linked to the keeper
        $this->assertSame(1, DB::table('ip_address_mac_address')
            ->where('ip_address_id', $this->keeperId)
            ->where('mac_address_id', $this->macY->id)
            ->count());

        $this->assertSame(0, DB::table('ip_address_mac_address')
            ->whereIn('ip_address_id', [$this->dupeSameCaseId, $this->dupeUpperCaseId])
            ->count());
    }

    public function test_repoints_and_merges_dhcp_leases(): void
    {
        $this->seedDuplicates();

        $this->runMigration();

        // Collision on unique (integration, ip_address_id): keep the most
        // recently updated row
        $ciscoRows = DB::table('dhcp_leases')
            ->where('integration', 'cisco')
            ->where('ip_address_id', $this->keeperId)
            ->get();
        $this->assertCount(1, $ciscoRows);
        $this->assertSame('new-host', $ciscoRows->first()->hostname);

        // Clean repoint
        $this->assertSame(1, DB::table('dhcp_leases')
            ->where('integration', 'opnsense')
            ->where('ip_address_id', $this->keeperId)
            ->count());

        $this->assertSame(0, DB::table('dhcp_leases')
            ->whereIn('ip_address_id', [$this->dupeSameCaseId, $this->dupeUpperCaseId])
            ->count());
    }

    public function test_repoints_audit_log_morphs(): void
    {
        $this->seedDuplicates();

        $this->runMigration();

        $ipMorph = (new IpAddress)->getMorphClass();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ip.created',
            'subject_type' => $ipMorph,
            'subject_id' => $this->keeperId,
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'subject_type' => $ipMorph,
            'subject_id' => $this->dupeSameCaseId,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ip_mac.linked',
            'related_type' => $ipMorph,
            'related_id' => $this->keeperId,
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'related_type' => $ipMorph,
            'related_id' => $this->dupeUpperCaseId,
        ]);
    }

    public function test_adds_unique_index_on_empty_table(): void
    {
        $this->dropUniqueIndex();

        $this->runMigration();

        $this->assertContains(self::UNIQUE_INDEX, Schema::getIndexListing('ip_addresses'));
    }

    public function test_adds_unique_index_on_address(): void
    {
        $this->seedDuplicates();

        $this->runMigration();

        $this->assertContains(self::UNIQUE_INDEX, Schema::getIndexListing('ip_addresses'));

        $this->expectException(QueryException::class);

        DB::table('ip_addresses')->insert([
            'address' => self::ADDRESS,
            'last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
