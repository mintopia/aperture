<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Step 1: Add the integration column if it doesn't already exist
        if (! Schema::hasColumn('dhcp_leases', 'integration')) {
            Schema::table('dhcp_leases', function (Blueprint $table): void {
                $table->string('integration')->nullable()->after('id');
            });
        }

        // Step 2: Backfill existing rows from capability_assignments
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

        // Step 3: Drop the FK on mac_address_id (if it still exists)
        // Must be a separate Schema::table call from the index drop for MySQL compatibility
        if ($this->foreignKeyExists('dhcp_leases', 'dhcp_leases_mac_address_id_foreign')) {
            Schema::table('dhcp_leases', function (Blueprint $table): void {
                $table->dropForeign(['mac_address_id']);
            });
        }

        // Step 4: Drop the old unique index (if it still exists)
        // On MySQL, the composite unique index (ip_address_id, mac_address_id) may also
        // back the ip_address_id FK (since ip_address_id is the leftmost column).
        // We must ensure a standalone index exists on ip_address_id first so MySQL has
        // an index to use for that FK when we drop the composite unique.
        if ($this->indexExists('dhcp_leases', 'dhcp_leases_ip_address_id_mac_address_id_unique')) {
            // Add a standalone index on ip_address_id to satisfy the FK requirement
            if (! $this->indexExists('dhcp_leases', 'dhcp_leases_ip_address_id_index')) {
                Schema::table('dhcp_leases', function (Blueprint $table): void {
                    $table->index('ip_address_id', 'dhcp_leases_ip_address_id_index');
                });
            }

            Schema::table('dhcp_leases', function (Blueprint $table): void {
                $table->dropUnique(['ip_address_id', 'mac_address_id']);
            });
        }

        // Step 5: Re-add the FK on mac_address_id (if it was dropped and not yet restored)
        if (! $this->foreignKeyExists('dhcp_leases', 'dhcp_leases_mac_address_id_foreign')) {
            Schema::table('dhcp_leases', function (Blueprint $table): void {
                $table->foreign('mac_address_id')->references('id')->on('mac_addresses')->cascadeOnDelete();
            });
        }

        // Step 6: Add the new unique index on (integration, ip_address_id)
        // Note: the standalone ip_address_id index from Step 4 is kept permanently because
        // MySQL requires a leftmost-prefix index to back the ip_address_id FK, and the new
        // unique index (integration, ip_address_id) has ip_address_id as the second column.
        if (! $this->indexExists('dhcp_leases', 'dhcp_leases_integration_ip_unique')) {
            Schema::table('dhcp_leases', function (Blueprint $table): void {
                $table->unique(['integration', 'ip_address_id'], 'dhcp_leases_integration_ip_unique');
            });
        }
    }

    public function down(): void
    {
        // Step 1: Drop the new unique index (if it exists)
        if ($this->indexExists('dhcp_leases', 'dhcp_leases_integration_ip_unique')) {
            Schema::table('dhcp_leases', function (Blueprint $table): void {
                $table->dropUnique('dhcp_leases_integration_ip_unique');
            });
        }

        // Step 2: Drop the FK on mac_address_id so we can recreate the old unique index
        // Must be separate Schema::table call from index creation for MySQL compatibility
        if ($this->foreignKeyExists('dhcp_leases', 'dhcp_leases_mac_address_id_foreign')) {
            Schema::table('dhcp_leases', function (Blueprint $table): void {
                $table->dropForeign(['mac_address_id']);
            });
        }

        // Step 3: Restore the old unique index (if it doesn't exist)
        if (! $this->indexExists('dhcp_leases', 'dhcp_leases_ip_address_id_mac_address_id_unique')) {
            Schema::table('dhcp_leases', function (Blueprint $table): void {
                $table->unique(['ip_address_id', 'mac_address_id']);
            });
        }

        // Step 4: Drop the standalone ip_address_id index (if it exists)
        // The composite unique index now covers ip_address_id as its leftmost column
        if ($this->indexExists('dhcp_leases', 'dhcp_leases_ip_address_id_index')) {
            Schema::table('dhcp_leases', function (Blueprint $table): void {
                $table->dropIndex('dhcp_leases_ip_address_id_index');
            });
        }

        // Step 5: Re-add the FK on mac_address_id (if it was dropped)
        if (! $this->foreignKeyExists('dhcp_leases', 'dhcp_leases_mac_address_id_foreign')) {
            Schema::table('dhcp_leases', function (Blueprint $table): void {
                $table->foreign('mac_address_id')->references('id')->on('mac_addresses')->cascadeOnDelete();
            });
        }

        // Step 6: Drop the integration column (if it exists)
        if (Schema::hasColumn('dhcp_leases', 'integration')) {
            Schema::table('dhcp_leases', function (Blueprint $table): void {
                $table->dropColumn('integration');
            });
        }
    }

    /**
     * Check whether a foreign key constraint exists on a table.
     * Works on both MySQL and SQLite.
     */
    private function foreignKeyExists(string $table, string $foreignKey): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            return $this->foreignKeyExistsSqlite($table, $foreignKey);
        }

        // MySQL / MariaDB — query information_schema
        $database = Schema::getConnection()->getDatabaseName();

        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('TABLE_SCHEMA', $database)
            ->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_NAME', $foreignKey)
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->exists();
    }

    /**
     * Check whether a foreign key constraint exists on SQLite.
     * SQLite doesn't name FK constraints in information_schema style,
     * so we inspect the foreign_key_list pragma and match by convention.
     *
     * Laravel names FKs as "{table}_{column}_foreign", so we extract
     * the column from the constraint name and check if a FK references
     * that column.
     */
    private function foreignKeyExistsSqlite(string $table, string $foreignKey): bool
    {
        // Extract the column name from Laravel's FK naming convention: {table}_{column}_foreign
        $prefix = $table.'_';
        $suffix = '_foreign';

        if (str_starts_with($foreignKey, $prefix) && str_ends_with($foreignKey, $suffix)) {
            $column = substr($foreignKey, strlen($prefix), -strlen($suffix));
        } else {
            return false;
        }

        $foreignKeys = Schema::getConnection()->getSchemaBuilder()->getForeignKeys($table);

        foreach ($foreignKeys as $fk) {
            if (in_array($column, $fk['columns'], true)) {
                return true;
            }
        }

        return false;
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
