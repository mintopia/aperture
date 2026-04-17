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
        Schema::create('switch_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('switch_config_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('ports_created')->default(0);
            $table->unsignedInteger('ports_updated')->default(0);
            $table->unsignedInteger('macs_created')->default(0);
            $table->unsignedInteger('macs_updated')->default(0);
            $table->timestamps();

            $table->index(['switch_config_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('switch_sync_runs');
    }
};
