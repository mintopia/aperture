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
        Schema::create('switch_port_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('switch_port_id')->constrained()->cascadeOnDelete();
            $table->text('config_text');
            $table->string('config_hash');
            $table->timestamp('last_fetched_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('switch_port_configs');
    }
};
