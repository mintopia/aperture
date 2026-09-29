<?php

declare(strict_types=1);

use App\Models\IpAddress;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ip_addresses', function (Blueprint $table): void {
            $table->string('address_sort', 32)->nullable()->index()->after('address');
        });

        DB::table('ip_addresses')->select(['id', 'address'])->orderBy('id')->each(function (object $row): void {
            DB::table('ip_addresses')->where('id', $row->id)->update([
                'address_sort' => IpAddress::sortKey((string) $row->address),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('ip_addresses', function (Blueprint $table): void {
            $table->dropColumn('address_sort');
        });
    }
};
