<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dhcp_sync_states', function (Blueprint $table): void {
            $table->id();
            $table->string('integration');
            $table->string('address_family');
            $table->string('dataset');
            $table->unsignedInteger('empty_count')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamps();

            $table->unique(['integration', 'address_family', 'dataset']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dhcp_sync_states');
    }
};
