<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('dhcp_leases')->where('hostname', '')->update(['hostname' => null]);

        $blankMacIds = DB::table('mac_addresses')->where('mac_address', '')->pluck('id');
        if ($blankMacIds->isEmpty()) {
            return;
        }

        DB::table('dhcp_leases')->whereIn('mac_address_id', $blankMacIds)->update(['mac_address_id' => null]);
        DB::table('mac_addresses')->whereIn('id', $blankMacIds)->delete();
    }

    public function down(): void {}
};
