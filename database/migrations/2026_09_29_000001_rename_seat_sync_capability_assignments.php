<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $table = DB::table('capability_assignments');

        if ($table->clone()->where('capability', 'seat-picker')->exists()) {
            $table->clone()->where('capability', 'seat-sync')->delete();

            return;
        }

        $table->clone()->where('capability', 'seat-sync')->update(['capability' => 'seat-picker']);
    }

    public function down(): void {}
};
