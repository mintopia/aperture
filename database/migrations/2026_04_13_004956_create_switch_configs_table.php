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
        Schema::create('switch_configs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('hostname');
            $table->string('type')->default('cisco');
            $table->string('username');
            $table->longText('password');
            $table->longText('enable_password')->nullable();
            $table->boolean('enabled')->default(true);
            $table->integer('port')->default(22);
            $table->integer('timeout')->default(5);
            $table->timestamps();

            $table->unique('hostname');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('switch_configs');
    }
};
