<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dhcp_range_records', function (Blueprint $table): void {
            $table->id();
            $table->string('integration')->nullable()->index();
            $table->string('interface')->default('');
            $table->string('type');
            $table->string('subnet');
            $table->string('range_from');
            $table->string('range_to');
            $table->string('prefix')->nullable();
            $table->string('gateway')->nullable();
            $table->string('description')->nullable();
            $table->decimal('total_addresses', 39, 0)->nullable();
            $table->decimal('used_addresses', 39, 0)->nullable();
            $table->decimal('utilisation', 5, 4)->nullable();
            $table->timestamps();

            $table->unique(['integration', 'type', 'subnet', 'range_from', 'range_to'], 'dhcp_range_records_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dhcp_range_records');
    }
};
