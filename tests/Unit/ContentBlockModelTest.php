<?php

namespace Tests\Unit;

use App\Models\ContentBlock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentBlockModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_scope_returns_only_active_blocks(): void
    {
        ContentBlock::factory()->create(['is_active' => true, 'title' => 'Active']);
        ContentBlock::factory()->inactive()->create(['title' => 'Inactive']);

        $active = ContentBlock::active()->get();

        $this->assertCount(1, $active);
        $this->assertEquals('Active', $active->first()->title);
    }

    public function test_active_scope_orders_by_sort_order(): void
    {
        ContentBlock::factory()->create(['sort_order' => 30, 'title' => 'Third', 'is_active' => true]);
        ContentBlock::factory()->create(['sort_order' => 10, 'title' => 'First', 'is_active' => true]);
        ContentBlock::factory()->create(['sort_order' => 20, 'title' => 'Second', 'is_active' => true]);

        $blocks = ContentBlock::active()->get();

        $this->assertEquals(['First', 'Second', 'Third'], $blocks->pluck('title')->toArray());
    }

    public function test_settings_cast_to_array(): void
    {
        $block = ContentBlock::factory()->create([
            'settings' => ['key' => 'value', 'nested' => ['a' => 1]],
        ]);

        $block->refresh();

        $this->assertIsArray($block->settings);
        $this->assertEquals('value', $block->settings['key']);
        $this->assertEquals(1, $block->settings['nested']['a']);
    }

    public function test_factory_creates_valid_model(): void
    {
        $block = ContentBlock::factory()->create();

        $this->assertDatabaseHas('content_blocks', ['id' => $block->id]);
        $this->assertNotEmpty($block->type);
        $this->assertNotEmpty($block->title);
        $this->assertIsBool($block->is_active);
    }
}
