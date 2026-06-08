<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dhcp_snooping_observations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('switch_config_id')->constrained('switch_configs')->cascadeOnDelete();
            $table->unsignedInteger('vlan')->default(0);
            $table->string('ip');
            $table->string('mac');
            $table->string('interface')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('observed_at');
            $table->timestamps();

            $table->unique(['switch_config_id', 'vlan', 'ip', 'mac'], 'dhcp_snooping_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dhcp_snooping_observations');
    }
};
