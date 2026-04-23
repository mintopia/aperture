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
        // 1. Create ip_address_mac_address pivot table
        Schema::create('ip_address_mac_address', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ip_address_id')->constrained('ip_addresses')->cascadeOnDelete();
            $table->foreignId('mac_address_id')->constrained('mac_addresses')->cascadeOnDelete();
            $table->string('source');
            $table->timestamp('last_seen_at')->useCurrent();
            $table->timestamps();
            $table->unique(['ip_address_id', 'mac_address_id']);
        });

        // 2. Create dhcp_leases table
        Schema::create('dhcp_leases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ip_address_id')->constrained('ip_addresses')->cascadeOnDelete();
            $table->foreignId('mac_address_id')->constrained('mac_addresses')->cascadeOnDelete();
            $table->string('hostname')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['ip_address_id', 'mac_address_id']);
        });

        // 3. Create audit_logs table
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('action');
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->string('related_type')->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->string('actor_type')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('process');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');
            $table->index(['subject_type', 'subject_id']);
            $table->index('action');
            $table->index('created_at');
        });

        // 4. Add mac_address_id FK to switch_port_macs
        Schema::table('switch_port_macs', function (Blueprint $table): void {
            $table->foreignId('mac_address_id')->nullable()->after('mac_address')->constrained('mac_addresses')->nullOnDelete();
        });

        // 5. Data migration: copy ip_addresses.mac_address_id into pivot
        $ipMacRows = DB::table('ip_addresses')
            ->whereNotNull('mac_address_id')
            ->select('id as ip_address_id', 'mac_address_id', 'last_seen_at')
            ->get();

        foreach ($ipMacRows->chunk(500) as $chunk) {
            $pivotRows = $chunk->map(fn ($row): array => [
                'ip_address_id' => $row->ip_address_id,
                'mac_address_id' => $row->mac_address_id,
                'source' => 'auth',
                'last_seen_at' => $row->last_seen_at ?? now(),
                'created_at' => now(),
                'updated_at' => now(),
            ])->toArray();

            DB::table('ip_address_mac_address')->insert($pivotRows);
        }

        // 6. Populate switch_port_macs.mac_address_id by matching mac_address string
        $macLookup = DB::table('mac_addresses')->pluck('id', 'mac_address');
        DB::table('switch_port_macs')->orderBy('id')->chunk(500, function ($rows) use ($macLookup): void {
            foreach ($rows as $row) {
                $macId = $macLookup[$row->mac_address] ?? null;
                if ($macId !== null) {
                    DB::table('switch_port_macs')
                        ->where('id', $row->id)
                        ->update(['mac_address_id' => $macId]);
                }
            }
        });

        // 7. Drop old columns from ip_addresses
        // SQLite 3.35+ does not allow dropping a column that has a FK constraint
        // defined on it, so we use a driver-aware approach.
        if (DB::getDriverName() === 'sqlite') {
            $this->dropIpAddressColumnsForSqlite();
        } else {
            Schema::table('ip_addresses', function (Blueprint $table): void {
                $table->dropForeign(['mac_address_id']);
                $table->dropForeign(['user_id']);
                $table->dropColumn(['mac_address_id', 'user_id']);
            });
        }

        // 8. Drop old columns from mac_addresses
        if (DB::getDriverName() === 'sqlite') {
            $this->dropMacAddressColumnsForSqlite();
        } else {
            Schema::table('mac_addresses', function (Blueprint $table): void {
                $table->dropColumn(['allowed', 'allowed_at']);
            });
        }
    }

    public function down(): void
    {
        // Restore ip_addresses columns
        Schema::table('ip_addresses', function (Blueprint $table): void {
            $table->foreignId('mac_address_id')->nullable()->constrained('mac_addresses')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
        });

        // Restore mac_addresses columns
        Schema::table('mac_addresses', function (Blueprint $table): void {
            $table->boolean('allowed')->default(false);
            $table->timestamp('allowed_at')->nullable();
        });

        // Drop mac_address_id from switch_port_macs
        Schema::table('switch_port_macs', function (Blueprint $table): void {
            if (DB::getDriverName() !== 'sqlite') {
                $table->dropForeign(['mac_address_id']);
            }

            $table->dropColumn('mac_address_id');
        });

        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('dhcp_leases');
        Schema::dropIfExists('ip_address_mac_address');
    }

    /**
     * Drop mac_address_id and user_id from ip_addresses using SQLite-compatible
     * table rebuild, since SQLite 3.35+ forbids dropping FK-constrained columns.
     */
    private function dropIpAddressColumnsForSqlite(): void
    {
        DB::statement('PRAGMA foreign_keys = OFF');

        DB::statement('CREATE TABLE ip_addresses_new AS SELECT id, address, internet_enabled, rate_limit_enabled, dns_filtering_enabled, comment, last_seen_at, expires_at, created_at, updated_at FROM ip_addresses');
        DB::statement('DROP TABLE ip_addresses');
        DB::statement('ALTER TABLE ip_addresses_new RENAME TO ip_addresses');

        DB::statement('PRAGMA foreign_keys = ON');
    }

    /**
     * Drop allowed and allowed_at from mac_addresses using SQLite-compatible
     * table rebuild, since SQLite may have constraints preventing direct column drops.
     */
    private function dropMacAddressColumnsForSqlite(): void
    {
        DB::statement('PRAGMA foreign_keys = OFF');

        DB::statement('CREATE TABLE mac_addresses_new AS SELECT id, mac_address, user_id, source, description, created_at, updated_at FROM mac_addresses');
        DB::statement('DROP TABLE mac_addresses');
        DB::statement('ALTER TABLE mac_addresses_new RENAME TO mac_addresses');

        DB::statement('PRAGMA foreign_keys = ON');
    }
};
