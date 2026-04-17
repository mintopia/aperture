<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('switch_ports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('switch_config_id')->constrained()->cascadeOnDelete();
            $table->string('port_name');
            $table->string('port_number');
            $table->string('switch_description')->nullable();
            $table->string('admin_notes')->nullable();
            $table->unsignedSmallInteger('access_vlan')->nullable();
            $table->string('switchport_mode')->nullable();
            $table->string('speed')->nullable();
            $table->string('status')->default('down');
            $table->string('admin_status')->default('up');
            $table->string('duplex')->nullable();
            $table->string('poe_status')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['switch_config_id', 'port_name']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('switch_ports');
    }
};
