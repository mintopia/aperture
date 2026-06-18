<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * total_addresses must hold exact decimal numeric strings up to 2^128
     * (39 digits). A DECIMAL(39,0) column gives SQLite NUMERIC affinity,
     * which silently converts values beyond 2^63-1 to REAL and corrupts
     * them on read (e.g. 2^64 becomes 1.8446744073709552E+19). Store the
     * value as a string so it round-trips verbatim on every driver.
     */
    public function up(): void
    {
        Schema::table('dhcp_range_records', function (Blueprint $table): void {
            $table->string('total_addresses', 64)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('dhcp_range_records', function (Blueprint $table): void {
            $table->decimal('total_addresses', 39, 0)->nullable()->change();
        });
    }
};
