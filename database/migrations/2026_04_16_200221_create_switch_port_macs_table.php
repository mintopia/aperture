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
        Schema::create('switch_port_macs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('switch_port_id')->constrained()->cascadeOnDelete();
            $table->string('mac_address');
            $table->unsignedSmallInteger('vlan')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['switch_port_id', 'mac_address', 'vlan']);
            $table->index('mac_address');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('switch_port_macs');
    }
};
