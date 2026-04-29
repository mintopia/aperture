<?php

namespace Tests\Unit;

use App\Models\ContentBlock;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ContentBlockModelTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_active_scope_returns_only_active_blocks(): void
    {
        ContentBlock::factory()->create(['is_active' => true, 'title' => 'Active']);
        ContentBlock::factory()->inactive()->create(['title' => 'Inactive']);

        $active = ContentBlock::active()->get();

        $this->assertCount(1, $active);
        $this->assertEquals('Active', $active->first()->title);
    }

    public function test_active_scope_orders_by_grid_row_then_grid_col(): void
    {
        ContentBlock::factory()->create(['grid_row' => 2, 'grid_col' => 1, 'title' => 'Row2Col1', 'is_active' => true]);
        ContentBlock::factory()->create(['grid_row' => 1, 'grid_col' => 3, 'title' => 'Row1Col3', 'is_active' => true]);
        ContentBlock::factory()->create(['grid_row' => 1, 'grid_col' => 1, 'title' => 'Row1Col1', 'is_active' => true]);

        $blocks = ContentBlock::active()->get();

        $this->assertEquals(['Row1Col1', 'Row1Col3', 'Row2Col1'], $blocks->pluck('title')->toArray());
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

    public function test_col_span_and_row_span_are_declared_as_integer_casts(): void
    {
        $block = new ContentBlock;
        $casts = $block->getCasts();

        $this->assertArrayHasKey('col_span', $casts, 'col_span must be declared in casts to ensure MySQL returns integers');
        $this->assertSame('integer', $casts['col_span']);
        $this->assertArrayHasKey('row_span', $casts, 'row_span must be declared in casts to ensure MySQL returns integers');
        $this->assertSame('integer', $casts['row_span']);
    }

    public function test_grid_col_and_grid_row_are_declared_as_integer_casts(): void
    {
        $block = new ContentBlock;
        $casts = $block->getCasts();

        $this->assertArrayHasKey('grid_col', $casts, 'grid_col must be declared in casts to ensure MySQL returns integers');
        $this->assertSame('integer', $casts['grid_col']);
        $this->assertArrayHasKey('grid_row', $casts, 'grid_row must be declared in casts to ensure MySQL returns integers');
        $this->assertSame('integer', $casts['grid_row']);
    }
}
