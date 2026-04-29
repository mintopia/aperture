<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GeneralSettingsControllerCoverImageTest extends TestCase
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

    public function test_admin_can_upload_cover_image(): void
    {
        Queue::fake();
        Storage::fake('public');
        $admin = $this->createAdminUser();

        $file = UploadedFile::fake()->image('cover.png', 1200, 400);

        $response = $this->actingAs($admin)->post('/admin/content/settings/cover-image', [
            'cover_image' => $file,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Cover image uploaded.');
        Storage::disk('public')->assertExists('branding/cover.png');
        $this->assertNotNull(Setting::get('dashboard.cover_image'));
    }

    public function test_upload_rejects_non_image(): void
    {
        Queue::fake();
        Storage::fake('public');
        $admin = $this->createAdminUser();

        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        $response = $this->actingAs($admin)->post('/admin/content/settings/cover-image', [
            'cover_image' => $file,
        ]);

        $response->assertSessionHasErrors('cover_image');
    }

    public function test_upload_rejects_file_over_5mb(): void
    {
        Queue::fake();
        Storage::fake('public');
        $admin = $this->createAdminUser();

        $file = UploadedFile::fake()->image('large.png')->size(6000);

        $response = $this->actingAs($admin)->post('/admin/content/settings/cover-image', [
            'cover_image' => $file,
        ]);

        $response->assertSessionHasErrors('cover_image');
    }

    public function test_upload_rejects_image_below_600px_wide(): void
    {
        Queue::fake();
        Storage::fake('public');
        $admin = $this->createAdminUser();

        $file = UploadedFile::fake()->image('small.png', 400, 200);

        $response = $this->actingAs($admin)->post('/admin/content/settings/cover-image', [
            'cover_image' => $file,
        ]);

        $response->assertSessionHasErrors('cover_image');
    }

    public function test_upload_accepts_jpeg(): void
    {
        Queue::fake();
        Storage::fake('public');
        $admin = $this->createAdminUser();

        $file = UploadedFile::fake()->image('cover.jpg', 1200, 400);

        $response = $this->actingAs($admin)->post('/admin/content/settings/cover-image', [
            'cover_image' => $file,
        ]);

        $response->assertRedirect();
        Storage::disk('public')->assertExists('branding/cover.jpg');
    }

    public function test_upload_accepts_webp(): void
    {
        Queue::fake();
        Storage::fake('public');
        $admin = $this->createAdminUser();

        $file = UploadedFile::fake()->image('cover.webp', 1200, 400);

        $response = $this->actingAs($admin)->post('/admin/content/settings/cover-image', [
            'cover_image' => $file,
        ]);

        $response->assertRedirect();
        Storage::disk('public')->assertExists('branding/cover.webp');
    }

    public function test_admin_can_delete_cover_image(): void
    {
        Queue::fake();
        Storage::fake('public');
        $admin = $this->createAdminUser();

        // Upload first
        $file = UploadedFile::fake()->image('cover.png', 1200, 400);
        $this->actingAs($admin)->post('/admin/content/settings/cover-image', [
            'cover_image' => $file,
        ]);

        Storage::disk('public')->assertExists('branding/cover.png');

        // Now delete
        $response = $this->actingAs($admin)->delete('/admin/content/settings/cover-image');

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Cover image removed.');
        Storage::disk('public')->assertMissing('branding/cover.png');
        $this->assertNull(Setting::get('dashboard.cover_image'));
    }

    public function test_non_admin_cannot_upload_cover_image(): void
    {
        Queue::fake();
        Storage::fake('public');
        $user = User::factory()->create();

        $file = UploadedFile::fake()->image('cover.png', 1200, 400);

        $response = $this->actingAs($user)->post('/admin/content/settings/cover-image', [
            'cover_image' => $file,
        ]);

        $response->assertForbidden();
    }

    public function test_non_admin_cannot_delete_cover_image(): void
    {
        Queue::fake();
        Storage::fake('public');
        $user = User::factory()->create();

        $response = $this->actingAs($user)->delete('/admin/content/settings/cover-image');

        $response->assertForbidden();
    }

    public function test_settings_show_includes_cover_image_url(): void
    {
        Queue::fake();
        Storage::fake('public');
        $admin = $this->createAdminUser();

        // Upload a cover image first
        $file = UploadedFile::fake()->image('cover.png', 1200, 400);
        $this->actingAs($admin)->post('/admin/content/settings/cover-image', [
            'cover_image' => $file,
        ]);

        $response = $this->actingAs($admin)->get('/admin/content/settings');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('settings.cover_image_url')
            ->where('settings.cover_image_url', fn ($value) => str_contains($value, 'branding/cover.png'))
        );
    }

    public function test_settings_show_has_null_cover_image_url_when_no_cover_image(): void
    {
        Queue::fake();
        Storage::fake('public');
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/admin/content/settings');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('settings.cover_image_url', null)
        );
    }
}
