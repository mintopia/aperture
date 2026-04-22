<?php

namespace Tests\Feature\Admin;

use App\Models\Page;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PageControllerTest extends TestCase
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

    public function test_admin_can_view_pages_index(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        Page::factory()->count(3)->create();

        $response = $this->actingAs($admin)->get('/admin/content/pages');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Content/Pages/Index')
            ->has('pages', 3)
        );
    }

    public function test_admin_can_view_create_form(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/admin/content/pages/create');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Content/Pages/Create')
        );
    }

    public function test_admin_can_create_page(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->post('/admin/content/pages', [
            'title' => 'Test Page',
            'slug' => 'test-page',
            'content' => 'Some content here.',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('pages', [
            'title' => 'Test Page',
            'slug' => 'test-page',
        ]);
    }

    public function test_admin_can_view_edit_form(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $page = Page::factory()->create();

        $response = $this->actingAs($admin)->get("/admin/content/pages/{$page->id}/edit");

        $response->assertOk();
        $response->assertInertia(fn ($p) => $p
            ->component('Admin/Content/Pages/Edit')
            ->has('page')
        );
    }

    public function test_admin_can_update_page(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $page = Page::factory()->create(['title' => 'Old Title', 'slug' => 'old-title']);

        $response = $this->actingAs($admin)->put("/admin/content/pages/{$page->id}", [
            'title' => 'New Title',
            'slug' => 'new-title',
            'content' => 'Updated content.',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('pages', [
            'id' => $page->id,
            'title' => 'New Title',
            'slug' => 'new-title',
        ]);
    }

    public function test_admin_can_delete_page(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $page = Page::factory()->create();

        $response = $this->actingAs($admin)->delete("/admin/content/pages/{$page->id}");

        $response->assertRedirect();
        $this->assertDatabaseMissing('pages', ['id' => $page->id]);
    }

    public function test_slug_must_be_unique_on_create(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        Page::factory()->create(['slug' => 'existing-slug']);

        $response = $this->actingAs($admin)->post('/admin/content/pages', [
            'title' => 'Another Page',
            'slug' => 'existing-slug',
        ]);

        $response->assertSessionHasErrors('slug');
    }

    public function test_slug_must_be_unique_on_update_except_self(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $page = Page::factory()->create(['slug' => 'my-slug']);
        $other = Page::factory()->create(['slug' => 'other-slug']);

        // Updating with same slug as another page should fail
        $response = $this->actingAs($admin)->put("/admin/content/pages/{$page->id}", [
            'title' => $page->title,
            'slug' => 'other-slug',
        ]);

        $response->assertSessionHasErrors('slug');
    }

    public function test_slug_can_remain_the_same_on_update(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $page = Page::factory()->create(['title' => 'My Page', 'slug' => 'my-page']);

        $response = $this->actingAs($admin)->put("/admin/content/pages/{$page->id}", [
            'title' => 'My Page Updated',
            'slug' => 'my-page',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('pages', ['id' => $page->id, 'title' => 'My Page Updated']);
    }

    public function test_title_is_required_on_create(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->post('/admin/content/pages', [
            'slug' => 'some-slug',
        ]);

        $response->assertSessionHasErrors('title');
    }

    public function test_slug_is_required_on_create(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->post('/admin/content/pages', [
            'title' => 'Some Title',
        ]);

        $response->assertSessionHasErrors('slug');
    }

    public function test_slug_must_be_alpha_dash(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->post('/admin/content/pages', [
            'title' => 'Some Title',
            'slug' => 'invalid slug!',
        ]);

        $response->assertSessionHasErrors('slug');
    }

    public function test_non_admin_cannot_access_pages(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin/content/pages')->assertForbidden();
        $this->actingAs($user)->get('/admin/content/pages/create')->assertForbidden();
        $this->actingAs($user)->post('/admin/content/pages', [])->assertForbidden();
    }
}
