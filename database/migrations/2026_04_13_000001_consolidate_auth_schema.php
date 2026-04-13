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
        Schema::table('users', function (Blueprint $table) {
            $table->string('external_id')->nullable()->unique()->after('blocked');
            $table->longText('access_token')->nullable()->after('external_id');
            $table->longText('refresh_token')->nullable()->after('access_token');
            $table->timestamp('token_expires_at')->nullable()->after('refresh_token');
            $table->string('avatar_url')->nullable()->after('token_expires_at');
        });

        Schema::dropIfExists('user_authentications');
        Schema::dropIfExists('auth_providers');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('auth_providers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('class');
            $table->string('client_id')->nullable()->default(null);
            $table->longText('client_secret')->nullable()->default(null);
            $table->boolean('enabled')->default(false);
            $table->timestamps();
        });

        Schema::create('user_authentications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('auth_provider_id');
            $table->string('external_id');
            $table->longText('access_token')->nullable()->default(null);
            $table->longText('refresh_token')->nullable()->default(null);
            $table->timestamp('token_expires_at')->nullable()->default(null);
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('auth_provider_id')->references('id')->on('auth_providers')->cascadeOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['external_id', 'access_token', 'refresh_token', 'token_expires_at', 'avatar_url']);
        });
    }
};
