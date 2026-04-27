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

class GeneralSettingsControllerLogoTest extends TestCase
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

    public function test_admin_can_upload_logo(): void
    {
        Queue::fake();
        Storage::fake('public');
        $admin = $this->createAdminUser();

        $file = UploadedFile::fake()->image('logo.png', 256, 256);

        $response = $this->actingAs($admin)->post('/admin/content/settings/logo', [
            'logo' => $file,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Logo uploaded.');
        Storage::disk('public')->assertExists('branding/logo.png');
        $this->assertEquals('true', Setting::get('general.site_logo'));
    }

    public function test_upload_rejects_non_image(): void
    {
        Queue::fake();
        Storage::fake('public');
        $admin = $this->createAdminUser();

        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        $response = $this->actingAs($admin)->post('/admin/content/settings/logo', [
            'logo' => $file,
        ]);

        $response->assertSessionHasErrors('logo');
    }

    public function test_upload_rejects_file_over_2mb(): void
    {
        Queue::fake();
        Storage::fake('public');
        $admin = $this->createAdminUser();

        $file = UploadedFile::fake()->image('large.png')->size(3000);

        $response = $this->actingAs($admin)->post('/admin/content/settings/logo', [
            'logo' => $file,
        ]);

        $response->assertSessionHasErrors('logo');
    }

    public function test_upload_rejects_non_square_image(): void
    {
        Queue::fake();
        Storage::fake('public');
        $admin = $this->createAdminUser();

        $file = UploadedFile::fake()->image('wide.png', 256, 128);

        $response = $this->actingAs($admin)->post('/admin/content/settings/logo', [
            'logo' => $file,
        ]);

        $response->assertSessionHasErrors('logo');
    }

    public function test_upload_rejects_image_below_64x64(): void
    {
        Queue::fake();
        Storage::fake('public');
        $admin = $this->createAdminUser();

        $file = UploadedFile::fake()->image('small.png', 32, 32);

        $response = $this->actingAs($admin)->post('/admin/content/settings/logo', [
            'logo' => $file,
        ]);

        $response->assertSessionHasErrors('logo');
    }

    public function test_upload_accepts_jpeg(): void
    {
        Queue::fake();
        Storage::fake('public');
        $admin = $this->createAdminUser();

        $file = UploadedFile::fake()->image('logo.jpg', 256, 256);

        $response = $this->actingAs($admin)->post('/admin/content/settings/logo', [
            'logo' => $file,
        ]);

        $response->assertRedirect();
        Storage::disk('public')->assertExists('branding/logo.png');
    }

    public function test_upload_accepts_webp(): void
    {
        Queue::fake();
        Storage::fake('public');
        $admin = $this->createAdminUser();

        $file = UploadedFile::fake()->image('logo.webp', 256, 256);

        $response = $this->actingAs($admin)->post('/admin/content/settings/logo', [
            'logo' => $file,
        ]);

        $response->assertRedirect();
        Storage::disk('public')->assertExists('branding/logo.png');
    }

    public function test_admin_can_delete_logo(): void
    {
        Queue::fake();
        Storage::fake('public');
        $admin = $this->createAdminUser();

        // Upload first
        $file = UploadedFile::fake()->image('logo.png', 256, 256);
        $this->actingAs($admin)->post('/admin/content/settings/logo', [
            'logo' => $file,
        ]);

        Storage::disk('public')->assertExists('branding/logo.png');

        // Now delete
        $response = $this->actingAs($admin)->delete('/admin/content/settings/logo');

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Logo removed.');
        Storage::disk('public')->assertMissing('branding/logo.png');
        $this->assertNull(Setting::get('general.site_logo'));
    }

    public function test_non_admin_cannot_upload_logo(): void
    {
        Queue::fake();
        Storage::fake('public');
        $user = User::factory()->create();

        $file = UploadedFile::fake()->image('logo.png', 256, 256);

        $response = $this->actingAs($user)->post('/admin/content/settings/logo', [
            'logo' => $file,
        ]);

        $response->assertForbidden();
    }

    public function test_non_admin_cannot_delete_logo(): void
    {
        Queue::fake();
        Storage::fake('public');
        $user = User::factory()->create();

        $response = $this->actingAs($user)->delete('/admin/content/settings/logo');

        $response->assertForbidden();
    }

    public function test_settings_show_includes_site_logo_url(): void
    {
        Queue::fake();
        Storage::fake('public');
        $admin = $this->createAdminUser();

        // Upload a logo first
        $file = UploadedFile::fake()->image('logo.png', 256, 256);
        $this->actingAs($admin)->post('/admin/content/settings/logo', [
            'logo' => $file,
        ]);

        $response = $this->actingAs($admin)->get('/admin/content/settings');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('settings.site_logo_url')
            ->where('settings.site_logo_url', fn ($value) => str_contains($value, 'branding/logo.png'))
        );
    }

    public function test_settings_show_has_null_logo_url_when_no_logo(): void
    {
        Queue::fake();
        Storage::fake('public');
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/admin/content/settings');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('settings.site_logo_url', null)
        );
    }
}
