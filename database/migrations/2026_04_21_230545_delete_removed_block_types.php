<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('content_blocks')
            ->whereIn('type', ['event_info', 'network_stats', 'connection_status'])
            ->delete();
    }

    public function down(): void
    {
        // Rows cannot be restored — this is a data cleanup migration
    }
};
