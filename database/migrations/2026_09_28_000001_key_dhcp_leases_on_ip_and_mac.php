<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OLD_UNIQUE_INDEX = 'dhcp_leases_integration_ip_unique';

    private const NEW_UNIQUE_INDEX = 'dhcp_leases_ip_address_id_mac_address_id_unique';

    private const IP_INDEX = 'dhcp_leases_ip_address_id_index';

    /**
     * A lease's identity is (ip_address_id, mac_address_id); integration only
     * records which integration reported it, and must not be part of the
     * identity key. Backfill NULL integrations from the active DHCP
     * capability assignment, dedupe rows that now collide on the
     * (ip_address_id, mac_address_id) key, then re-key the unique index.
     */
    public function up(): void
    {
        $this->backfillIntegration();

        foreach ($this->duplicateGroups() as $group) {
            $this->mergeGroup($group);
        }

        $this->rekeyUniqueIndex();
    }

    public function down(): void
    {
        // Best effort: the backfill/dedupe is not reversed (merged/deleted
        // rows are unrecoverable). Only the unique index shape is restored.
        if ($this->indexExists('dhcp_leases', self::NEW_UNIQUE_INDEX)) {
            Schema::table('dhcp_leases', function (Blueprint $table): void {
                $table->dropUnique(self::NEW_UNIQUE_INDEX);
            });
        }

        if (! $this->indexExists('dhcp_leases', self::OLD_UNIQUE_INDEX)) {
            Schema::table('dhcp_leases', function (Blueprint $table): void {
                $table->unique(['integration', 'ip_address_id'], self::OLD_UNIQUE_INDEX);
            });
        }
    }

    /**
     * Backfill rows with a NULL integration from the active DHCP capability
     * assignment, if one exists. Mirrors the backfill step from the
     * 2026_06_08_000002 migration.
     */
    private function backfillIntegration(): void
    {
        $activeProvider = null;

        try {
            $row = DB::table('capability_assignments')
                ->where('capability', 'dhcp')
                ->first();
            $activeProvider = $row?->integration;
        } catch (Throwable) {
            // Table may not exist in fresh installs
        }

        if ($activeProvider !== null) {
            DB::table('dhcp_leases')
                ->whereNull('integration')
                ->update(['integration' => $activeProvider]);
        }
    }

    /**
     * Group dhcp_leases ids by (ip_address_id, mac_address_id), skipping rows
     * with a NULL mac_address_id (a NULL mac never collides under the new
     * unique index), and return only the groups containing duplicates, ids
     * ascending.
     *
     * @return list<non-empty-list<int>>
     */
    private function duplicateGroups(): array
    {
        /** @var array<string, non-empty-list<int>> $groups */
        $groups = [];

        DB::table('dhcp_leases')
            ->select(['id', 'ip_address_id', 'mac_address_id'])
            ->whereNotNull('mac_address_id')
            ->orderBy('id')
            ->chunkById(500, function (Collection $rows) use (&$groups): void {
                foreach ($rows as $row) {
                    $groups[$row->ip_address_id.':'.$row->mac_address_id][] = (int) $row->id;
                }
            });

        return array_values(array_filter($groups, fn (array $ids): bool => count($ids) > 1));
    }

    /**
     * Keep exactly one row per (ip_address_id, mac_address_id) group:
     * prefer a non-null integration, then the latest updated_at, then the
     * highest id. Delete the rest.
     *
     * @param  non-empty-list<int>  $group
     */
    private function mergeGroup(array $group): void
    {
        $rows = DB::table('dhcp_leases')->whereIn('id', $group)->get();

        $keeper = $rows->sort(function (object $a, object $b): int {
            $aHasIntegration = $a->integration !== null;
            $bHasIntegration = $b->integration !== null;

            if ($aHasIntegration !== $bHasIntegration) {
                return $aHasIntegration ? -1 : 1;
            }

            $updatedCompare = strtotime((string) $b->updated_at) <=> strtotime((string) $a->updated_at);
            if ($updatedCompare !== 0) {
                return $updatedCompare;
            }

            return $b->id <=> $a->id;
        })->first();

        if ($keeper === null) {
            return;
        }

        $dupeIds = $rows->pluck('id')
            ->reject(fn (int $id): bool => $id === $keeper->id)
            ->values()
            ->all();

        if ($dupeIds !== []) {
            DB::table('dhcp_leases')->whereIn('id', $dupeIds)->delete();
        }
    }

    /**
     * Drop the old (integration, ip_address_id) unique index and add the new
     * (ip_address_id, mac_address_id) one. The standalone ip_address_id
     * index is kept so MySQL still has a leftmost-prefix index to back the
     * ip_address_id FK.
     */
    private function rekeyUniqueIndex(): void
    {
        if (! $this->indexExists('dhcp_leases', self::IP_INDEX)) {
            Schema::table('dhcp_leases', function (Blueprint $table): void {
                $table->index('ip_address_id', self::IP_INDEX);
            });
        }

        if ($this->indexExists('dhcp_leases', self::OLD_UNIQUE_INDEX)) {
            Schema::table('dhcp_leases', function (Blueprint $table): void {
                $table->dropUnique(self::OLD_UNIQUE_INDEX);
            });
        }

        if (! $this->indexExists('dhcp_leases', self::NEW_UNIQUE_INDEX)) {
            Schema::table('dhcp_leases', function (Blueprint $table): void {
                $table->unique(['ip_address_id', 'mac_address_id'], self::NEW_UNIQUE_INDEX);
            });
        }
    }

    /**
     * Check whether an index exists on a table.
     * Uses Laravel's built-in index listing which works across drivers.
     */
    private function indexExists(string $table, string $index): bool
    {
        return in_array($index, Schema::getIndexListing($table), true);
    }
};
