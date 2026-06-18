<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Role;
use App\Models\User;
use App\Services\LogoService;
use App\Services\ThemeService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ThemeSettingsControllerTest extends TestCase
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

    public function test_old_theme_route_no_longer_exists(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $this->actingAs($admin)->get('/admin/settings/theme')->assertNotFound();
        $this->actingAs($admin)->put('/admin/settings/theme', [
            'accent_hue' => 230,
            'theme_mode' => 'dark',
        ])->assertNotFound();
    }

    public function test_theme_includes_site_logo_fields_when_no_logo(): void
    {
        Queue::fake();
        $theme = app(ThemeService::class)->getTheme();

        $this->assertArrayHasKey('has_site_logo', $theme);
        $this->assertArrayHasKey('site_logo_url', $theme);
        $this->assertArrayHasKey('favicon_urls', $theme);
        $this->assertFalse($theme['has_site_logo']);
        $this->assertNull($theme['site_logo_url']);
        $this->assertNull($theme['favicon_urls']);
    }

    public function test_theme_includes_site_logo_url_when_logo_exists(): void
    {
        Queue::fake();
        Storage::fake('public');
        $file = UploadedFile::fake()->image('logo.png', 128, 128);
        app(LogoService::class)->store($file);

        // Need a fresh instance since ThemeService caches
        $themeService = new ThemeService(app(LogoService::class));
        $theme = $themeService->getTheme();

        $this->assertTrue($theme['has_site_logo']);
        $this->assertNotNull($theme['site_logo_url']);
        $this->assertIsArray($theme['favicon_urls']);
        $this->assertArrayHasKey('16', $theme['favicon_urls']);
        $this->assertArrayHasKey('32', $theme['favicon_urls']);
        $this->assertArrayHasKey('180', $theme['favicon_urls']);
    }
}
