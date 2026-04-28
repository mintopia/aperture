<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('capability_assignments')
            ->where('integration', 'librenms')
            ->whereIn('capability', ['ip-mac', 'port-mac'])
            ->delete();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['ip-mac', 'port-mac'] as $capability) {
            if (! DB::table('capability_assignments')->where('capability', $capability)->exists()) {
                DB::table('capability_assignments')->insert([
                    'capability' => $capability,
                    'integration' => 'librenms',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
};
