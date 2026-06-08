<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dhcp_leases', function (Blueprint $table): void {
            $table->foreignId('mac_address_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('dhcp_leases', function (Blueprint $table): void {
            $table->foreignId('mac_address_id')->nullable(false)->change();
        });
    }
};
