<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Allow ip_addresses.internet_enabled to be null, representing an IP with no
     * explicit allow/block decision (e.g. freshly discovered). null is treated as
     * blocked for enforcement (deny-by-default) but displayed as "—".
     */
    public function up(): void
    {
        Schema::table('ip_addresses', function (Blueprint $table): void {
            $table->boolean('internet_enabled')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        DB::table('ip_addresses')->whereNull('internet_enabled')->update(['internet_enabled' => false]);

        Schema::table('ip_addresses', function (Blueprint $table): void {
            $table->boolean('internet_enabled')->nullable(false)->default(false)->change();
        });
    }
};
