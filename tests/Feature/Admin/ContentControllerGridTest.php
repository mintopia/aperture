<?php

namespace Tests\Feature\Admin;

use App\Models\ContentBlock;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ContentControllerGridTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function createAdminUser(): User
    {
        $user = User::factory()->create();
        $role = new Role;
        $role->code = 'admin';
        $role->name = 'Admin';
        $role->save();
        $user->roles()->attach($role);

        return $user;
    }

    public function test_cannot_create_duplicate_singleton_block(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        ContentBlock::factory()->create(['type' => 'bandwidth']);

        $response = $this->actingAs($admin)->postJson('/admin/content', [
            'type' => 'bandwidth',
            'title' => 'Another Bandwidth',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['type']);
    }

    public function test_can_create_duplicate_non_singleton_block(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        ContentBlock::factory()->create(['type' => 'custom_markdown', 'title' => 'First']);

        $response = $this->actingAs($admin)->postJson('/admin/content', [
            'type' => 'custom_markdown',
            'title' => 'Second',
        ]);

        $response->assertCreated();
        $this->assertEquals(2, ContentBlock::where('type', 'custom_markdown')->count());
    }

    public function test_update_layout_saves_grid_positions(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $block1 = ContentBlock::factory()->create(['grid_col' => 1, 'grid_row' => 1]);
        $block2 = ContentBlock::factory()->create(['grid_col' => 2, 'grid_row' => 1]);

        $response = $this->actingAs($admin)->putJson('/admin/content/layout', [
            'blocks' => [
                ['id' => $block1->id, 'grid_col' => 1, 'grid_row' => 2, 'col_span' => 2, 'row_span' => 1],
                ['id' => $block2->id, 'grid_col' => 3, 'grid_row' => 1, 'col_span' => 1, 'row_span' => 1],
            ],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('content_blocks', ['id' => $block1->id, 'grid_col' => 1, 'grid_row' => 2, 'col_span' => 2]);
        $this->assertDatabaseHas('content_blocks', ['id' => $block2->id, 'grid_col' => 3, 'grid_row' => 1]);
    }

    public function test_update_layout_validates_column_bounds(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $block = ContentBlock::factory()->create();

        $response = $this->actingAs($admin)->putJson('/admin/content/layout', [
            'blocks' => [
                ['id' => $block->id, 'grid_col' => 4, 'grid_row' => 1, 'col_span' => 1, 'row_span' => 1],
            ],
        ]);

        $response->assertUnprocessable();
    }

    public function test_update_layout_validates_span_fits_grid(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $block = ContentBlock::factory()->create();

        $response = $this->actingAs($admin)->putJson('/admin/content/layout', [
            'blocks' => [
                ['id' => $block->id, 'grid_col' => 2, 'grid_row' => 1, 'col_span' => 3, 'row_span' => 1],
            ],
        ]);

        $response->assertUnprocessable();
    }

    public function test_update_layout_rejects_overlapping_blocks(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $block1 = ContentBlock::factory()->create();
        $block2 = ContentBlock::factory()->create();

        $response = $this->actingAs($admin)->putJson('/admin/content/layout', [
            'blocks' => [
                ['id' => $block1->id, 'grid_col' => 1, 'grid_row' => 1, 'col_span' => 2, 'row_span' => 1],
                ['id' => $block2->id, 'grid_col' => 2, 'grid_row' => 1, 'col_span' => 1, 'row_span' => 1],
            ],
        ]);

        $response->assertUnprocessable();
    }

    public function test_editor_page_loads(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        ContentBlock::factory()->count(3)->create();

        $response = $this->actingAs($admin)->get('/admin/content');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Content/Editor')
            ->has('blocks', 3)
        );
    }

    public function test_store_assigns_first_available_grid_position(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        ContentBlock::factory()->create(['grid_col' => 1, 'grid_row' => 1]);
        ContentBlock::factory()->create(['grid_col' => 2, 'grid_row' => 1]);

        $response = $this->actingAs($admin)->postJson('/admin/content', [
            'type' => 'custom_markdown',
            'title' => 'New Block',
        ]);

        $response->assertCreated();

        $block = ContentBlock::where('title', 'New Block')->first();
        $this->assertEquals(3, $block->grid_col);
        $this->assertEquals(1, $block->grid_row);
    }
}
