<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_blocks', function (Blueprint $table) {
            $table->integer('grid_col')->default(1)->after('is_active');
            $table->integer('grid_row')->default(1)->after('grid_col');
            $table->integer('col_span')->default(1)->after('grid_row');
            $table->integer('row_span')->default(1)->after('col_span');
        });

        // Assign default grid positions to existing blocks based on sort_order
        $blocks = DB::table('content_blocks')->orderBy('sort_order')->get();
        $col = 1;
        $row = 1;
        foreach ($blocks as $block) {
            DB::table('content_blocks')->where('id', $block->id)->update([
                'grid_col' => $col,
                'grid_row' => $row,
            ]);
            $col++;
            if ($col > 3) {
                $col = 1;
                $row++;
            }
        }

        // Rename pihole_toggle to dns_filter
        DB::table('content_blocks')
            ->where('type', 'pihole_toggle')
            ->update(['type' => 'dns_filter']);

        Schema::table('content_blocks', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }

    public function down(): void
    {
        Schema::table('content_blocks', function (Blueprint $table) {
            $table->integer('sort_order')->default(0)->after('is_active');
        });

        // Restore sort_order from grid positions
        $blocks = DB::table('content_blocks')
            ->orderBy('grid_row')
            ->orderBy('grid_col')
            ->get();
        $order = 10;
        foreach ($blocks as $block) {
            DB::table('content_blocks')->where('id', $block->id)->update([
                'sort_order' => $order,
            ]);
            $order += 10;
        }

        DB::table('content_blocks')
            ->where('type', 'dns_filter')
            ->update(['type' => 'pihole_toggle']);

        Schema::table('content_blocks', function (Blueprint $table) {
            $table->dropColumn(['grid_col', 'grid_row', 'col_span', 'row_span']);
        });
    }
};
