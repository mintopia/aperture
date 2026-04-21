<?php

namespace Database\Seeders;

use App\Models\ContentBlock;
use Illuminate\Database\Seeder;

class ContentBlockSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $blocks = [
            [
                'type' => 'event_info',
                'title' => 'Welcome to the LAN Party',
                'content' => 'Check the schedule and make the most of your time here. Have fun and play fair!',
                'sort_order' => 10,
                'is_active' => true,
                'settings' => null,
            ],
            [
                'type' => 'connection_status',
                'title' => 'Connection Status',
                'content' => null,
                'sort_order' => 20,
                'is_active' => true,
                'settings' => null,
            ],
            [
                'type' => 'bandwidth',
                'title' => 'Your Bandwidth',
                'content' => null,
                'sort_order' => 30,
                'is_active' => true,
                'settings' => null,
            ],
            [
                'type' => 'network_stats',
                'title' => 'Network Stats',
                'content' => null,
                'sort_order' => 40,
                'is_active' => true,
                'settings' => null,
            ],
            [
                'type' => 'dns_filter',
                'title' => 'DNS Ad Blocking',
                'content' => 'Toggle DNS filtering for your connection.',
                'sort_order' => 50,
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
