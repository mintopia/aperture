<?php

declare(strict_types=1);

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
        Schema::table('connection_test_logs', function (Blueprint $table) {
            $table->string('request_method')->nullable()->after('message');
            $table->text('request_url')->nullable()->after('request_method');
            $table->integer('response_status')->nullable()->after('request_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('connection_test_logs', function (Blueprint $table) {
            $table->dropColumn(['request_method', 'request_url', 'response_status']);
        });
    }
};
