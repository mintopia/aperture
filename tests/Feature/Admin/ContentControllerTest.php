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
            ->component('Admin/Content/Editor')
            ->has('blocks', 3)
            ->has('singletonTypes')
            ->has('existingTypes')
        );
    }

    public function test_editor_route_no_longer_exists(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/admin/content/editor');

        // The dedicated /content/editor GET route has been removed.
        // The URI now resolves to the resource {content} pattern with 'show' excluded,
        // so Laravel returns 405 Method Not Allowed rather than 404.
        $this->assertContains($response->getStatusCode(), [404, 405]);
    }

    public function test_admin_can_create_content_block(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->postJson('/admin/content', [
            'type' => 'custom_markdown',
            'title' => 'New Block',
            'content' => 'Some content',
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

    public function test_singleton_types_excludes_removed_types(): void
    {
        $this->assertNotContains('event_info', ContentBlock::SINGLETON_TYPES);
        $this->assertNotContains('network_stats', ContentBlock::SINGLETON_TYPES);
        $this->assertNotContains('connection_status', ContentBlock::SINGLETON_TYPES);
        $this->assertContains('connection_strip', ContentBlock::SINGLETON_TYPES);
        $this->assertContains('bandwidth', ContentBlock::SINGLETON_TYPES);
        $this->assertContains('dns_filter', ContentBlock::SINGLETON_TYPES);
    }
}
