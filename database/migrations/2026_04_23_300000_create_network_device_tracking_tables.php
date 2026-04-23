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
        // Drop each FK-constrained column in a separate Schema::table call so that
        // Laravel's SQLite grammar can rebuild the table once per call safely.
        Schema::table('ip_addresses', function (Blueprint $table): void {
            $table->dropForeign(['mac_address_id']);
            $table->dropColumn('mac_address_id');
        });

        Schema::table('ip_addresses', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });

        // 8. Drop old columns from mac_addresses
        Schema::table('mac_addresses', function (Blueprint $table): void {
            $table->dropColumn(['allowed', 'allowed_at']);
        });

        // 9. Remove old IntegrationConfig auto_allow keys
        DB::table('integration_configs')->where('integration', 'auto_allow')->delete();
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
};
