<?php

declare(strict_types=1);

use App\Models\IpAddress;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const UNIQUE_INDEX = 'ip_addresses_address_unique';

    /**
     * Merge case-variant (and byte-identical) duplicate ip_addresses rows into
     * the lowest-id keeper per lowercased address, repoint every reference,
     * then add a unique index on address so duplicates cannot reappear.
     *
     * Runs after 2026_06_10_000001_normalize_ipv6_addresses_lowercase, which
     * lowercased rows but intentionally skipped ones whose lowered value would
     * collide with an existing row; those collisions are resolved here.
     */
    public function up(): void
    {
        // On fresh databases the dedupe loop is a natural no-op; the unique
        // index is always created so every database — fresh or existing —
        // ends up with the same schema. addUniqueIndex() is idempotent via
        // its index-exists guard.
        foreach ($this->duplicateGroups() as $group) {
            $this->mergeGroup($group);
        }

        $this->addUniqueIndex();
    }

    public function down(): void
    {
        // The dedupe is intentionally not reversed: deleted duplicate rows and
        // their original foreign keys are unrecoverable once merged. Only the
        // unique index is removed.
        if (in_array(self::UNIQUE_INDEX, Schema::getIndexListing('ip_addresses'), true)) {
            Schema::table('ip_addresses', function (Blueprint $table): void {
                $table->dropUnique(self::UNIQUE_INDEX);
            });
        }
    }

    /**
     * Group ip_addresses ids by lowercased address (PHP-side, so the grouping
     * is identical on SQLite and MariaDB regardless of collation) and return
     * only the groups containing duplicates, ids ascending.
     *
     * @return list<non-empty-list<int>>
     */
    private function duplicateGroups(): array
    {
        /** @var array<string, non-empty-list<int>> $groups */
        $groups = [];

        DB::table('ip_addresses')
            ->select(['id', 'address'])
            ->orderBy('id')
            ->chunkById(500, function (Collection $rows) use (&$groups): void {
                foreach ($rows as $row) {
                    $groups[strtolower((string) $row->address)][] = (int) $row->id;
                }
            });

        return array_values(array_filter($groups, fn (array $ids): bool => count($ids) > 1));
    }

    /**
     * @param  non-empty-list<int>  $group  ids sharing one lowercased address, ascending
     */
    private function mergeGroup(array $group): void
    {
        $keeperId = $group[0];
        $dupeIds = array_slice($group, 1);
        $ipMorph = (new IpAddress)->getMorphClass();

        $rows = DB::table('ip_addresses')->whereIn('id', $group)->orderBy('id')->get()->keyBy('id');

        foreach ($dupeIds as $dupeId) {
            $this->mergeUserIpAddresses($keeperId, $dupeId);
            $this->mergeIpMacPivots($keeperId, $dupeId);
            $this->mergeDhcpLeases($keeperId, $dupeId);

            DB::table('audit_logs')
                ->where('subject_type', $ipMorph)
                ->where('subject_id', $dupeId)
                ->update(['subject_id' => $keeperId]);

            DB::table('audit_logs')
                ->where('related_type', $ipMorph)
                ->where('related_id', $dupeId)
                ->update(['related_id' => $keeperId]);
        }

        $this->mergeScalarsIntoKeeper($keeperId, $dupeIds, $rows);

        DB::table('ip_addresses')->whereIn('id', $dupeIds)->delete();
    }

    /**
     * user_ip_addresses: repoint to the keeper; on a (user_id, ip_address_id)
     * collision keep a single row carrying the max last_seen_at.
     */
    private function mergeUserIpAddresses(int $keeperId, int $dupeId): void
    {
        $rows = DB::table('user_ip_addresses')->where('ip_address_id', $dupeId)->get();

        foreach ($rows as $row) {
            $existing = DB::table('user_ip_addresses')
                ->where('user_id', $row->user_id)
                ->where('ip_address_id', $keeperId)
                ->first();

            if ($existing === null) {
                DB::table('user_ip_addresses')
                    ->where('user_id', $row->user_id)
                    ->where('ip_address_id', $dupeId)
                    ->update(['ip_address_id' => $keeperId]);

                continue;
            }

            if ($this->isAfter($row->last_seen_at, $existing->last_seen_at)) {
                DB::table('user_ip_addresses')
                    ->where('user_id', $existing->user_id)
                    ->where('ip_address_id', $keeperId)
                    ->update(['last_seen_at' => $row->last_seen_at]);
            }

            DB::table('user_ip_addresses')
                ->where('user_id', $row->user_id)
                ->where('ip_address_id', $dupeId)
                ->delete();
        }
    }

    /**
     * ip_address_mac_address: repoint to the keeper; on a unique
     * (ip_address_id, mac_address_id) collision merge into the keeper row,
     * keeping the max last_seen_at and the earliest-created row's source.
     */
    private function mergeIpMacPivots(int $keeperId, int $dupeId): void
    {
        $rows = DB::table('ip_address_mac_address')->where('ip_address_id', $dupeId)->get();

        foreach ($rows as $row) {
            $existing = DB::table('ip_address_mac_address')
                ->where('ip_address_id', $keeperId)
                ->where('mac_address_id', $row->mac_address_id)
                ->first();

            if ($existing === null) {
                DB::table('ip_address_mac_address')
                    ->where('id', $row->id)
                    ->update(['ip_address_id' => $keeperId]);

                continue;
            }

            $updates = [];

            if ($this->isAfter($row->last_seen_at, $existing->last_seen_at)) {
                $updates['last_seen_at'] = $row->last_seen_at;
            }

            // Earliest-created row's source wins; ties keep the keeper's.
            if ($this->isAfter($existing->created_at, $row->created_at)) {
                $updates['source'] = $row->source;
            }

            if ($updates !== []) {
                DB::table('ip_address_mac_address')->where('id', $existing->id)->update($updates);
            }

            DB::table('ip_address_mac_address')->where('id', $row->id)->delete();
        }
    }

    /**
     * dhcp_leases: repoint to the keeper; on a unique
     * (integration, ip_address_id) collision keep the most recently updated
     * lease's data on the keeper row.
     */
    private function mergeDhcpLeases(int $keeperId, int $dupeId): void
    {
        $rows = DB::table('dhcp_leases')->where('ip_address_id', $dupeId)->get();

        foreach ($rows as $row) {
            $existing = DB::table('dhcp_leases')
                ->where('ip_address_id', $keeperId)
                ->when(
                    $row->integration === null,
                    fn ($query) => $query->whereNull('integration'),
                    fn ($query) => $query->where('integration', $row->integration),
                )
                ->first();

            if ($existing === null) {
                DB::table('dhcp_leases')
                    ->where('id', $row->id)
                    ->update(['ip_address_id' => $keeperId]);

                continue;
            }

            if ($this->isAfter($row->updated_at, $existing->updated_at)) {
                DB::table('dhcp_leases')->where('id', $existing->id)->update([
                    'mac_address_id' => $row->mac_address_id,
                    'hostname' => $row->hostname,
                    'expires_at' => $row->expires_at,
                    'updated_at' => $row->updated_at,
                ]);
            }

            DB::table('dhcp_leases')->where('id', $row->id)->delete();
        }
    }

    /**
     * internet_enabled = logical OR, last_seen_at = max across the group,
     * comment = keeper's unless null (first non-null duplicate fills in).
     *
     * @param  list<int>  $dupeIds
     * @param  Collection<int|string, object>  $rows
     */
    private function mergeScalarsIntoKeeper(int $keeperId, array $dupeIds, Collection $rows): void
    {
        $keeper = $rows->get($keeperId);
        if ($keeper === null) {
            return;
        }

        $internetEnabled = (bool) $keeper->internet_enabled;
        $lastSeenAt = $keeper->last_seen_at;
        $comment = $keeper->comment;

        foreach ($dupeIds as $dupeId) {
            $dupe = $rows->get($dupeId);
            if ($dupe === null) {
                continue;
            }

            $internetEnabled = $internetEnabled || (bool) $dupe->internet_enabled;

            if ($this->isAfter($dupe->last_seen_at, $lastSeenAt)) {
                $lastSeenAt = $dupe->last_seen_at;
            }

            $comment ??= $dupe->comment;
        }

        DB::table('ip_addresses')->where('id', $keeperId)->update([
            'internet_enabled' => $internetEnabled,
            'last_seen_at' => $lastSeenAt,
            'comment' => $comment,
        ]);
    }

    private function addUniqueIndex(): void
    {
        if (in_array(self::UNIQUE_INDEX, Schema::getIndexListing('ip_addresses'), true)) {
            return;
        }

        Schema::table('ip_addresses', function (Blueprint $table): void {
            $table->unique('address', self::UNIQUE_INDEX);
        });
    }

    /**
     * Chronological comparison of two raw datetime column values.
     */
    private function isAfter(mixed $candidate, mixed $current): bool
    {
        if ($candidate === null) {
            return false;
        }

        if ($current === null) {
            return true;
        }

        return strtotime((string) $candidate) > strtotime((string) $current);
    }
};
