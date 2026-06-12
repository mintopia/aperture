<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('system_events');
    }

    public function down(): void
    {
        Schema::create('system_events', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 100)->index();
            $table->string('level', 20)->index();
            $table->text('message');
            $table->json('data')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }
};
