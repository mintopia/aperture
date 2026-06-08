<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dhcp_pool_statuses', function (Blueprint $table): void {
            $table->id();
            $table->string('integration');
            $table->string('address_family');
            $table->decimal('total', 39, 0)->default(0);
            $table->decimal('used', 39, 0)->default(0);
            $table->decimal('available', 39, 0)->default(0);
            $table->decimal('utilisation', 5, 4)->default(0);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['integration', 'address_family']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dhcp_pool_statuses');
    }
};
