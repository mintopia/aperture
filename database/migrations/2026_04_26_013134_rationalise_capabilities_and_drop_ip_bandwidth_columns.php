<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Remove stale capabilities
        DB::table('capability_assignments')
            ->whereIn('capability', [
                'authentication', 'sso', 'user-info',
                'aggregate-stats', 'device-metrics',
                'firewall', 'host-stats',
            ])
            ->delete();

        // Rename user-bandwidth → ip-bandwidth
        DB::table('capability_assignments')
            ->where('capability', 'user-bandwidth')
            ->update(['capability' => 'ip-bandwidth']);

        // Add new capabilities (only if not already present)
        if (! DB::table('capability_assignments')->where('capability', 'ip-mac')->exists()) {
            DB::table('capability_assignments')->insert([
                'capability' => 'ip-mac',
                'integration' => 'librenms',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (! DB::table('capability_assignments')->where('capability', 'port-mac')->exists()) {
            DB::table('capability_assignments')->insert([
                'capability' => 'port-mac',
                'integration' => 'librenms',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Drop dead columns from ip_addresses
        Schema::table('ip_addresses', function (Blueprint $table) {
            $table->dropColumn(['received', 'sent']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ip_addresses', function (Blueprint $table) {
            $table->unsignedBigInteger('received')->default(0);
            $table->unsignedBigInteger('sent')->default(0);
        });

        DB::table('capability_assignments')
            ->where('capability', 'ip-bandwidth')
            ->update(['capability' => 'user-bandwidth']);

        DB::table('capability_assignments')
            ->whereIn('capability', ['ip-mac', 'port-mac'])
            ->delete();
    }
};
