<?php

namespace Database\Seeders;

use App\Models\ContentBlock;
use Illuminate\Database\Seeder;

class ContentBlockSeeder extends Seeder
{
    public function run(): void
    {
        $blocks = [
            [
                'type' => 'connection_strip',
                'title' => 'Connection Status',
                'content' => null,
                'grid_col' => 1,
                'grid_row' => 1,
                'col_span' => 3,
                'row_span' => 1,
                'is_active' => true,
                'settings' => null,
            ],
            [
                'type' => 'custom_markdown',
                'title' => 'Welcome to the LAN Party',
                'content' => "Check the schedule and make the most of your time here.\n\n**Have fun** and play fair!",
                'grid_col' => 1,
                'grid_row' => 2,
                'col_span' => 2,
                'row_span' => 1,
                'is_active' => true,
                'settings' => null,
            ],
            [
                'type' => 'bandwidth',
                'title' => 'Your Bandwidth',
                'content' => null,
                'grid_col' => 3,
                'grid_row' => 2,
                'col_span' => 1,
                'row_span' => 1,
                'is_active' => true,
                'settings' => null,
            ],
            [
                'type' => 'dns_filter',
                'title' => 'DNS Ad Blocking',
                'content' => 'Toggle DNS filtering for your connection.',
                'grid_col' => 1,
                'grid_row' => 3,
                'col_span' => 1,
                'row_span' => 1,
                'is_active' => true,
                'settings' => null,
            ],
        ];

        foreach ($blocks as $block) {
            ContentBlock::firstOrCreate(
                ['type' => $block['type']],
                $block,
            );
        }
    }
}
