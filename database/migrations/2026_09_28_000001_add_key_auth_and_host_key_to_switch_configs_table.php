<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('switch_configs', function (Blueprint $table): void {
            $table->longText('password')->nullable()->change();
            $table->longText('private_key')->nullable()->after('enable_password');
            $table->longText('passphrase')->nullable()->after('private_key');
            $table->text('host_key')->nullable()->after('passphrase');
        });
    }

    public function down(): void
    {
        Schema::table('switch_configs', function (Blueprint $table): void {
            $table->dropColumn(['private_key', 'passphrase', 'host_key']);
        });
    }
};
