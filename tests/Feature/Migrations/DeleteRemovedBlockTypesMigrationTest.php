<?php

namespace Tests\Feature\Migrations;

use App\Models\ContentBlock;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class DeleteRemovedBlockTypesMigrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_migration_removes_orphaned_block_types(): void
    {
        // RefreshDatabase already ran all migrations (deleting any existing rows).
        // Insert rows of removed types and re-run the migration to prove it works.
        ContentBlock::factory()->create(['type' => 'event_info']);
        ContentBlock::factory()->create(['type' => 'network_stats']);
        ContentBlock::factory()->create(['type' => 'connection_status']);
        ContentBlock::factory()->create(['type' => 'custom_markdown']);

        $migration = require database_path('migrations/2026_04_21_230545_delete_removed_block_types.php');
        $migration->up();

        $this->assertDatabaseMissing('content_blocks', ['type' => 'event_info']);
        $this->assertDatabaseMissing('content_blocks', ['type' => 'network_stats']);
        $this->assertDatabaseMissing('content_blocks', ['type' => 'connection_status']);
        $this->assertDatabaseHas('content_blocks', ['type' => 'custom_markdown']);
    }
}
