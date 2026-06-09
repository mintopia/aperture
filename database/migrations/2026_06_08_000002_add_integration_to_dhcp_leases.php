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
        Schema::table('dhcp_leases', function (Blueprint $table): void {
            $table->string('integration')->nullable()->after('id');
        });

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

        Schema::table('dhcp_leases', function (Blueprint $table): void {
            $table->dropForeign(['mac_address_id']);
            $table->dropUnique(['ip_address_id', 'mac_address_id']);
            $table->unique(['integration', 'ip_address_id'], 'dhcp_leases_integration_ip_unique');
            $table->foreign('mac_address_id')->references('id')->on('mac_addresses')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('dhcp_leases', function (Blueprint $table): void {
            $table->dropForeign(['mac_address_id']);
            $table->dropUnique('dhcp_leases_integration_ip_unique');
            $table->unique(['ip_address_id', 'mac_address_id']);
            $table->foreign('mac_address_id')->references('id')->on('mac_addresses')->cascadeOnDelete();
            $table->dropColumn('integration');
        });
    }
};
