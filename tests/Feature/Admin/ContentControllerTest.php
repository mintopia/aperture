<?php

namespace Tests\Feature\Admin;

use App\Models\ContentBlock;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ContentControllerTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_admin_can_view_content_blocks(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        ContentBlock::factory()->count(3)->create();

        $response = $this->actingAs($admin)->get('/admin/content');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Content/Index')
            ->has('blocks', 3)
        );
    }

    public function test_admin_can_create_content_block(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->postJson('/admin/content', [
            'type' => 'custom_markdown',
            'title' => 'New Block',
            'content' => 'Some content',
            'sort_order' => 5,
            'is_active' => true,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('content_blocks', ['title' => 'New Block']);
    }

    public function test_admin_can_update_content_block(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $block = ContentBlock::factory()->create(['title' => 'Old Title']);

        $response = $this->actingAs($admin)->putJson('/admin/content/'.$block->id, [
            'title' => 'Updated Title',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('content_blocks', ['id' => $block->id, 'title' => 'Updated Title']);
    }

    public function test_admin_can_delete_content_block(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $block = ContentBlock::factory()->create();

        $response = $this->actingAs($admin)->deleteJson('/admin/content/'.$block->id);

        $response->assertNoContent();
        $this->assertDatabaseMissing('content_blocks', ['id' => $block->id]);
    }

    public function test_admin_can_reorder_content_blocks(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $block1 = ContentBlock::factory()->create(['sort_order' => 10]);
        $block2 = ContentBlock::factory()->create(['sort_order' => 20]);

        $response = $this->actingAs($admin)->postJson('/admin/content/reorder', [
            'blocks' => [
                ['id' => $block1->id, 'sort_order' => 20],
                ['id' => $block2->id, 'sort_order' => 10],
            ],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('content_blocks', ['id' => $block1->id, 'sort_order' => 20]);
        $this->assertDatabaseHas('content_blocks', ['id' => $block2->id, 'sort_order' => 10]);
    }

    public function test_create_validates_required_fields(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->postJson('/admin/content', []);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['type', 'title']);
    }

    public function test_non_admin_cannot_manage_content(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/admin/content');

        $response->assertForbidden();
    }
}
