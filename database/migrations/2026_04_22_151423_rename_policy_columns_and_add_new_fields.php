<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->renameColumn('blocked', 'internet_blocked');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('internet_enabled')->default(false)->after('internet_blocked');
            $table->boolean('rate_limit_enabled')->default(false)->after('internet_enabled');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('dns_filtering_enabled')->default(false)->change();
        });

        Schema::table('ip_addresses', function (Blueprint $table): void {
            $table->renameColumn('allowed', 'internet_enabled');
            $table->renameColumn('limited', 'rate_limit_enabled');
        });

        Schema::table('ip_addresses', function (Blueprint $table): void {
            $table->boolean('dns_filtering_enabled')->default(false)->after('rate_limit_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('ip_addresses', function (Blueprint $table): void {
            $table->dropColumn('dns_filtering_enabled');
        });

        Schema::table('ip_addresses', function (Blueprint $table): void {
            $table->renameColumn('internet_enabled', 'allowed');
            $table->renameColumn('rate_limit_enabled', 'limited');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('dns_filtering_enabled')->default(true)->change();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['internet_enabled', 'rate_limit_enabled']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->renameColumn('internet_blocked', 'blocked');
        });
    }
};
