# Custom Logo & Favicon Upload Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Allow admins to upload a custom logo image that serves as the site favicon, replaces the captive portal "A" mark, and appears in the user dashboard header. Admin UI stays Aperture-branded.

**Architecture:** A `LogoService` handles image processing (resize, favicon generation) and storage in `storage/app/public/branding/`. The service integrates with the existing `ThemeService`/`InjectTheme` pipeline to share logo URLs to all views. Upload/delete endpoints are added to `GeneralSettingsController`.

**Tech Stack:** Laravel 12 (GD extension for image processing), Vue 3 + Inertia.js, Blade templates, Playwright E2E

---

### Task 1: LogoService — Image Processing and Storage

**Files:**
- Create: `app/Services/LogoService.php`
- Create: `tests/Feature/LogoServiceTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/LogoServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\LogoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LogoServiceTest extends TestCase
{
    use RefreshDatabase;

    private LogoService $logoService;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->logoService = app(LogoService::class);
    }

    public function test_store_saves_logo_and_favicon_variants(): void
    {
        $file = UploadedFile::fake()->image('logo.png', 256, 256);

        $this->logoService->store($file);

        Storage::disk('public')->assertExists('branding/logo.png');
        Storage::disk('public')->assertExists('branding/favicon-16x16.png');
        Storage::disk('public')->assertExists('branding/favicon-32x32.png');
        Storage::disk('public')->assertExists('branding/apple-touch-icon.png');
    }

    public function test_store_sets_site_logo_setting(): void
    {
        $file = UploadedFile::fake()->image('logo.png', 256, 256);

        $this->logoService->store($file);

        $this->assertEquals('true', \App\Models\Setting::get('general.site_logo'));
    }

    public function test_store_converts_jpeg_to_png(): void
    {
        $file = UploadedFile::fake()->image('logo.jpg', 128, 128);

        $this->logoService->store($file);

        Storage::disk('public')->assertExists('branding/logo.png');
    }

    public function test_store_resizes_large_image_to_max_512(): void
    {
        $file = UploadedFile::fake()->image('logo.png', 1024, 1024);

        $this->logoService->store($file);

        Storage::disk('public')->assertExists('branding/logo.png');
        $path = Storage::disk('public')->path('branding/logo.png');
        $info = getimagesize($path);
        $this->assertLessThanOrEqual(512, $info[0]);
        $this->assertLessThanOrEqual(512, $info[1]);
    }

    public function test_exists_returns_false_when_no_logo(): void
    {
        $this->assertFalse($this->logoService->exists());
    }

    public function test_exists_returns_true_after_store(): void
    {
        $file = UploadedFile::fake()->image('logo.png', 64, 64);
        $this->logoService->store($file);

        $this->assertTrue($this->logoService->exists());
    }

    public function test_url_returns_null_when_no_logo(): void
    {
        $this->assertNull($this->logoService->url());
    }

    public function test_url_returns_path_with_cache_bust_after_store(): void
    {
        $file = UploadedFile::fake()->image('logo.png', 64, 64);
        $this->logoService->store($file);

        $url = $this->logoService->url();
        $this->assertStringContainsString('/storage/branding/logo.png', $url);
        $this->assertStringContainsString('?v=', $url);
    }

    public function test_favicon_urls_returns_null_when_no_logo(): void
    {
        $this->assertNull($this->logoService->faviconUrls());
    }

    public function test_favicon_urls_returns_keyed_array_after_store(): void
    {
        $file = UploadedFile::fake()->image('logo.png', 64, 64);
        $this->logoService->store($file);

        $urls = $this->logoService->faviconUrls();
        $this->assertIsArray($urls);
        $this->assertArrayHasKey('16', $urls);
        $this->assertArrayHasKey('32', $urls);
        $this->assertArrayHasKey('180', $urls);
        $this->assertStringContainsString('favicon-16x16.png', $urls['16']);
        $this->assertStringContainsString('favicon-32x32.png', $urls['32']);
        $this->assertStringContainsString('apple-touch-icon.png', $urls['180']);
    }

    public function test_delete_removes_all_files_and_setting(): void
    {
        $file = UploadedFile::fake()->image('logo.png', 128, 128);
        $this->logoService->store($file);

        $this->logoService->delete();

        Storage::disk('public')->assertMissing('branding/logo.png');
        Storage::disk('public')->assertMissing('branding/favicon-16x16.png');
        Storage::disk('public')->assertMissing('branding/favicon-32x32.png');
        Storage::disk('public')->assertMissing('branding/apple-touch-icon.png');
        $this->assertNull(\App\Models\Setting::get('general.site_logo'));
        $this->assertFalse($this->logoService->exists());
    }

    public function test_store_overwrites_previous_logo(): void
    {
        $file1 = UploadedFile::fake()->image('old.png', 64, 64);
        $this->logoService->store($file1);
        $url1 = $this->logoService->url();

        $file2 = UploadedFile::fake()->image('new.png', 128, 128);
        $this->logoService->store($file2);
        $url2 = $this->logoService->url();

        $this->assertTrue($this->logoService->exists());
        Storage::disk('public')->assertExists('branding/logo.png');
    }

    public function test_delete_when_no_logo_does_not_error(): void
    {
        $this->logoService->delete();
        $this->assertFalse($this->logoService->exists());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=LogoServiceTest`
Expected: FAIL — class `LogoService` not found

- [ ] **Step 3: Implement LogoService**

Create `app/Services/LogoService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class LogoService
{
    private const BRANDING_DIR = 'branding';

    private const FAVICON_SIZES = [
        '16' => 'favicon-16x16.png',
        '32' => 'favicon-32x32.png',
        '180' => 'apple-touch-icon.png',
    ];

    public function store(UploadedFile $file): void
    {
        $disk = Storage::disk('public');
        $disk->makeDirectory(self::BRANDING_DIR);

        $image = $this->loadImage($file);
        $width = imagesx($image);
        $height = imagesy($image);

        if ($width > 512 || $height > 512) {
            $image = $this->resize($image, 512, 512);
        }

        $this->savePng($image, $disk->path(self::BRANDING_DIR . '/logo.png'));

        foreach (self::FAVICON_SIZES as $size => $filename) {
            $resized = $this->resize($image, (int) $size, (int) $size);
            $this->savePng($resized, $disk->path(self::BRANDING_DIR . '/' . $filename));
            imagedestroy($resized);
        }

        imagedestroy($image);

        Setting::set('general.site_logo', 'Site Logo', 'true');
    }

    public function delete(): void
    {
        $disk = Storage::disk('public');

        $disk->delete(self::BRANDING_DIR . '/logo.png');
        foreach (self::FAVICON_SIZES as $filename) {
            $disk->delete(self::BRANDING_DIR . '/' . $filename);
        }

        $setting = Setting::whereCode('general.site_logo')->first();
        $setting?->delete();
    }

    public function exists(): bool
    {
        return Setting::get('general.site_logo') === 'true'
            && Storage::disk('public')->exists(self::BRANDING_DIR . '/logo.png');
    }

    public function url(): ?string
    {
        if (! $this->exists()) {
            return null;
        }

        $path = Storage::disk('public')->path(self::BRANDING_DIR . '/logo.png');
        $version = file_exists($path) ? filemtime($path) : 0;

        return asset('storage/' . self::BRANDING_DIR . '/logo.png') . '?v=' . $version;
    }

    /**
     * @return array<string, string>|null
     */
    public function faviconUrls(): ?array
    {
        if (! $this->exists()) {
            return null;
        }

        $urls = [];
        foreach (self::FAVICON_SIZES as $size => $filename) {
            $path = Storage::disk('public')->path(self::BRANDING_DIR . '/' . $filename);
            $version = file_exists($path) ? filemtime($path) : 0;
            $urls[$size] = asset('storage/' . self::BRANDING_DIR . '/' . $filename) . '?v=' . $version;
        }

        return $urls;
    }

    /**
     * @return \GdImage
     */
    private function loadImage(UploadedFile $file): \GdImage
    {
        $path = $file->getRealPath();
        $mime = $file->getMimeType();

        $image = match ($mime) {
            'image/png' => imagecreatefrompng($path),
            'image/jpeg' => imagecreatefromjpeg($path),
            'image/webp' => imagecreatefromwebp($path),
            default => imagecreatefrompng($path),
        };

        if ($image === false) {
            throw new \RuntimeException('Failed to load image');
        }

        return $image;
    }

    /**
     * @return \GdImage
     */
    private function resize(\GdImage $source, int $width, int $height): \GdImage
    {
        $dest = imagecreatetruecolor($width, $height);
        if ($dest === false) {
            throw new \RuntimeException('Failed to create image');
        }

        imagealphablending($dest, false);
        imagesavealpha($dest, true);

        imagecopyresampled(
            $dest, $source,
            0, 0, 0, 0,
            $width, $height,
            imagesx($source), imagesy($source)
        );

        return $dest;
    }

    private function savePng(\GdImage $image, string $path): void
    {
        imagepng($image, $path, 9);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=LogoServiceTest`
Expected: All 12 tests PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/LogoService.php tests/Feature/LogoServiceTest.php
git commit -m "feat: add LogoService for image processing and storage"
```

---

### Task 2: Controller Endpoints — Upload and Delete Logo

**Files:**
- Modify: `app/Http/Controllers/Admin/GeneralSettingsController.php`
- Modify: `routes/web.php:128-129`
- Create: `tests/Feature/Admin/GeneralSettingsControllerLogoTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Admin/GeneralSettingsControllerLogoTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GeneralSettingsControllerLogoTest extends TestCase
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
        $response->assertSessionHas('success');
        Storage::disk('public')->assertExists('branding/logo.png');
        Storage::disk('public')->assertExists('branding/favicon-32x32.png');
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

        $file = UploadedFile::fake()->image('logo.png', 256, 256)->size(3000);
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

        $file = UploadedFile::fake()->image('logo.png', 256, 128);
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

        $file = UploadedFile::fake()->image('logo.png', 32, 32);
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

        $file = UploadedFile::fake()->image('logo.jpg', 128, 128);
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

        $file = UploadedFile::fake()->image('logo.webp', 128, 128);
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

        $file = UploadedFile::fake()->image('logo.png', 128, 128);
        $this->actingAs($admin)->post('/admin/content/settings/logo', [
            'logo' => $file,
        ]);

        $response = $this->actingAs($admin)->delete('/admin/content/settings/logo');

        $response->assertRedirect();
        $response->assertSessionHas('success');
        Storage::disk('public')->assertMissing('branding/logo.png');
        $this->assertNull(Setting::get('general.site_logo'));
    }

    public function test_non_admin_cannot_upload_logo(): void
    {
        Queue::fake();
        Storage::fake('public');
        $user = User::factory()->create();

        $file = UploadedFile::fake()->image('logo.png', 128, 128);
        $this->actingAs($user)->post('/admin/content/settings/logo', [
            'logo' => $file,
        ])->assertForbidden();
    }

    public function test_non_admin_cannot_delete_logo(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->delete('/admin/content/settings/logo')->assertForbidden();
    }

    public function test_settings_show_includes_site_logo_url(): void
    {
        Queue::fake();
        Storage::fake('public');
        $admin = $this->createAdminUser();

        $file = UploadedFile::fake()->image('logo.png', 128, 128);
        $this->actingAs($admin)->post('/admin/content/settings/logo', [
            'logo' => $file,
        ]);

        $response = $this->actingAs($admin)->get('/admin/content/settings');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('settings.site_logo_url')
        );
    }

    public function test_settings_show_has_null_logo_url_when_no_logo(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/admin/content/settings');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('settings.site_logo_url', null)
        );
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=GeneralSettingsControllerLogoTest`
Expected: FAIL — routes not defined

- [ ] **Step 3: Add routes to web.php**

In `routes/web.php`, after the existing content settings routes (lines 128-129), add:

```php
Route::post('/content/settings/logo', [GeneralSettingsController::class, 'updateLogo'])->name('content.settings.logo.update');
Route::delete('/content/settings/logo', [GeneralSettingsController::class, 'deleteLogo'])->name('content.settings.logo.delete');
```

- [ ] **Step 4: Add controller methods and update show**

In `app/Http/Controllers/Admin/GeneralSettingsController.php`:

1. Inject `LogoService` as a constructor parameter
2. Add `site_logo_url` to the `show` method's settings array
3. Add `updateLogo` method with validation rules: `logo` required, image, mimes:png,jpg,jpeg,webp, max:2048, plus custom rules for square aspect and 64x64 minimum
4. Add `deleteLogo` method

```php
use App\Services\LogoService;
use Illuminate\Validation\Rules\File;

// Constructor:
public function __construct(
    private readonly LogoService $logoService,
) {}

// In show(), add to settings array:
'site_logo_url' => $this->logoService->url(),

// New methods:
public function updateLogo(Request $request): RedirectResponse
{
    $request->validate([
        'logo' => [
            'required',
            File::image()->types(['png', 'jpg', 'jpeg', 'webp'])->max(2048),
            function (string $attribute, mixed $value, \Closure $fail): void {
                if (! $value instanceof UploadedFile) {
                    return;
                }
                $size = getimagesize($value->getRealPath());
                if ($size === false) {
                    $fail('The logo must be a valid image.');
                    return;
                }
                [$width, $height] = $size;
                if ($width !== $height) {
                    $fail('The logo must be square (1:1 aspect ratio).');
                }
                if ($width < 64 || $height < 64) {
                    $fail('The logo must be at least 64x64 pixels.');
                }
            },
        ],
    ]);

    $this->logoService->store($request->file('logo'));

    return back()->with('success', 'Logo uploaded.');
}

public function deleteLogo(): RedirectResponse
{
    $this->logoService->delete();

    return back()->with('success', 'Logo removed.');
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact --filter=GeneralSettingsControllerLogoTest`
Expected: All 12 tests PASS

- [ ] **Step 6: Run existing GeneralSettingsControllerTest to check for regressions**

Run: `php artisan test --compact --filter=GeneralSettingsControllerTest`
Expected: All 24 tests PASS (constructor injection of LogoService should be auto-resolved)

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Admin/GeneralSettingsController.php routes/web.php tests/Feature/Admin/GeneralSettingsControllerLogoTest.php
git commit -m "feat: add logo upload and delete endpoints"
```

---

### Task 3: ThemeService + InjectTheme — Share Logo Data

**Files:**
- Modify: `app/Services/ThemeService.php`
- Modify: `app/Http/Middleware/InjectTheme.php`
- Modify: `tests/Feature/Admin/ThemeSettingsControllerTest.php`

- [ ] **Step 1: Write the failing tests**

Add tests to `tests/Feature/Admin/ThemeSettingsControllerTest.php` (or create a new `tests/Feature/ThemeServiceTest.php` if needed). The tests should verify that the theme data includes `has_site_logo`, `site_logo_url`, and `favicon_urls`. Check the existing ThemeSettingsControllerTest structure to determine where to add. If it tests the theme via HTTP responses, add assertions there. If a unit test is more appropriate, create `tests/Feature/ThemeServiceTest.php`.

The tests:

```php
public function test_theme_includes_site_logo_fields_when_no_logo(): void
{
    Queue::fake();
    $theme = app(\App\Services\ThemeService::class)->getTheme();

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
    app(\App\Services\LogoService::class)->store($file);

    $themeService = app(\App\Services\ThemeService::class);
    $theme = $themeService->getTheme();

    $this->assertTrue($theme['has_site_logo']);
    $this->assertNotNull($theme['site_logo_url']);
    $this->assertIsArray($theme['favicon_urls']);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=test_theme_includes_site_logo`
Expected: FAIL — keys not present in theme array

- [ ] **Step 3: Update ThemeService**

In `app/Services/ThemeService.php`:
1. Inject `LogoService` via constructor
2. Add `has_site_logo`, `site_logo_url`, and `favicon_urls` to the cached array

```php
use App\Services\LogoService;

// Add constructor:
public function __construct(
    private readonly LogoService $logoService,
) {}

// Add to the $this->cached array in getTheme():
'has_site_logo' => $this->logoService->exists(),
'site_logo_url' => $this->logoService->url(),
'favicon_urls' => $this->logoService->faviconUrls(),
```

- [ ] **Step 4: Update InjectTheme middleware**

In `app/Http/Middleware/InjectTheme.php`, add after existing `View::share` calls:

```php
View::share('siteLogoUrl', $theme['site_logo_url']);
View::share('hasSiteLogo', $theme['has_site_logo']);
View::share('faviconUrls', $theme['favicon_urls']);
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact --filter=test_theme_includes_site_logo`
Expected: PASS

- [ ] **Step 6: Run existing theme tests for regressions**

Run: `php artisan test --compact --filter=ThemeSettingsControllerTest`
Expected: All existing tests PASS

- [ ] **Step 7: Commit**

```bash
git add app/Services/ThemeService.php app/Http/Middleware/InjectTheme.php tests/Feature/Admin/ThemeSettingsControllerTest.php
git commit -m "feat: share logo URLs via ThemeService and InjectTheme"
```

---

### Task 4: Blade Templates — Favicon Links and Captive Portal Logo

**Files:**
- Modify: `resources/views/app.blade.php`
- Modify: `resources/views/layouts/captive.blade.php`
- Modify: `resources/views/captive/login.blade.php`

- [ ] **Step 1: Add favicon links to app.blade.php**

In `resources/views/app.blade.php`, add before the `@routes` directive (line 8):

```blade
@if($hasSiteLogo ?? false)
    <link rel="icon" type="image/png" sizes="32x32" href="{{ $faviconUrls['32'] }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ $faviconUrls['16'] }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ $faviconUrls['180'] }}">
@endif
```

- [ ] **Step 2: Add favicon links to captive layout**

In `resources/views/layouts/captive.blade.php`, add before the `@vite` directive (line 8):

```blade
@if($hasSiteLogo ?? false)
    <link rel="icon" type="image/png" sizes="32x32" href="{{ $faviconUrls['32'] }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ $faviconUrls['16'] }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ $faviconUrls['180'] }}">
@endif
```

- [ ] **Step 3: Update captive portal login logo mark**

In `resources/views/captive/login.blade.php`, replace the existing logo mark block (lines 27-29):

```blade
{{-- Logo mark --}}
<div class="mx-auto mb-4 inline-flex h-10 w-10 items-center justify-center rounded-[10px] bg-[var(--color-primary)]">
    <span class="font-heading text-lg font-extrabold tracking-tight text-[var(--color-bg)]">A</span>
</div>
```

With:

```blade
{{-- Logo mark --}}
@if($hasSiteLogo ?? false)
    <img src="{{ $siteLogoUrl }}" alt="{{ $siteTitle }}" class="mx-auto mb-4 h-10 w-10 rounded-[10px]" data-testid="captive-logo-image">
@else
    <div class="mx-auto mb-4 inline-flex h-10 w-10 items-center justify-center rounded-[10px] bg-[var(--color-primary)]">
        <span class="font-heading text-lg font-extrabold tracking-tight text-[var(--color-bg)]">A</span>
    </div>
@endif
```

- [ ] **Step 4: Run existing Blade/captive tests for regressions**

Run: `php artisan test --compact --filter=CaptiveController`
Expected: All existing tests PASS (Blade conditionals fall through to default "A" when no logo)

- [ ] **Step 5: Commit**

```bash
git add resources/views/app.blade.php resources/views/layouts/captive.blade.php resources/views/captive/login.blade.php
git commit -m "feat: add favicon links and conditional logo in captive portal"
```

---

### Task 5: AppLogo.vue — Conditional Image Display

**Files:**
- Modify: `resources/js/Components/AppLogo.vue`
- Modify: `tests/js/Components/AppLogo.spec.js`

- [ ] **Step 1: Write the failing tests**

Update `tests/js/Components/AppLogo.spec.js` to add tests for the logo image:

Add a second describe block with a different mock that includes `site_logo_url` in theme:

```javascript
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';

// We need two sets of tests: with and without logo
// Use vi.doMock for the "with logo" variant

describe('AppLogo (no logo)', () => {
    // Keep existing tests, mock usePage without theme.site_logo_url
    // ...existing 3 tests unchanged...
});

describe('AppLogo (with logo)', () => {
    // Tests for when site_logo_url is present in theme
    it('renders logo image when site_logo_url is present', async () => {
        // ...
    });

    it('logo image has correct alt text', async () => {
        // ...
    });

    it('logo image has correct data-testid', async () => {
        // ...
    });

    it('still renders site name text alongside image', async () => {
        // ...
    });
});
```

The full updated test file should test:
- No logo: text only, no `<img>`, has data-testid `app-logo`
- With logo: `<img>` with `data-testid="app-logo-image"`, correct `alt`, text still present, image `src` matches prop

- [ ] **Step 2: Run tests to verify new tests fail**

Run: `npx vitest run tests/js/Components/AppLogo.spec.js`
Expected: New "with logo" tests FAIL

- [ ] **Step 3: Update AppLogo.vue**

Replace `resources/js/Components/AppLogo.vue`:

```vue
<script setup>
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const appName = computed(() => page.props.appName || 'Aperture');
const logoUrl = computed(() => page.props.theme?.site_logo_url || null);
</script>

<template>
    <span class="flex items-center gap-2 font-heading text-lg font-bold text-[var(--color-text)]" data-testid="app-logo">
        <img
            v-if="logoUrl"
            :src="logoUrl"
            :alt="appName"
            class="h-6 w-6 rounded"
            data-testid="app-logo-image"
        />
        {{ appName }}
    </span>
</template>
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `npx vitest run tests/js/Components/AppLogo.spec.js`
Expected: All tests PASS

- [ ] **Step 5: Run PortalLayout tests for regressions**

Run: `npx vitest run tests/js/Layouts/PortalLayout.spec.js`
Expected: PASS (PortalLayout stubs AppLogo)

- [ ] **Step 6: Commit**

```bash
git add resources/js/Components/AppLogo.vue tests/js/Components/AppLogo.spec.js
git commit -m "feat: show custom logo image in AppLogo component"
```

---

### Task 6: Settings Page — Logo Upload UI

**Files:**
- Modify: `resources/js/Pages/Admin/Content/Settings.vue`
- Modify: `tests/js/Pages/Admin/Content/Settings.spec.js`

- [ ] **Step 1: Write the failing tests**

Add to `tests/js/Pages/Admin/Content/Settings.spec.js`:

```javascript
it('renders logo upload input', () => {
    const wrapper = mountPage();
    expect(wrapper.find('[data-testid="input-logo"]').exists()).toBe(true);
});

it('shows current logo preview when site_logo_url is set', () => {
    const wrapper = mountPage({ site_logo_url: '/storage/branding/logo.png?v=123' });
    expect(wrapper.find('[data-testid="logo-preview"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="logo-preview"]').attributes('src')).toBe(
        '/storage/branding/logo.png?v=123',
    );
});

it('does not show logo preview when no logo', () => {
    const wrapper = mountPage({ site_logo_url: null });
    expect(wrapper.find('[data-testid="logo-preview"]').exists()).toBe(false);
});

it('shows remove logo button when logo exists', () => {
    const wrapper = mountPage({ site_logo_url: '/storage/branding/logo.png?v=123' });
    expect(wrapper.find('[data-testid="action-remove-logo"]').exists()).toBe(true);
});

it('does not show remove button when no logo', () => {
    const wrapper = mountPage({ site_logo_url: null });
    expect(wrapper.find('[data-testid="action-remove-logo"]').exists()).toBe(false);
});

it('shows upload help text', () => {
    const wrapper = mountPage();
    expect(wrapper.text()).toContain('Square image');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `npx vitest run tests/js/Pages/Admin/Content/Settings.spec.js`
Expected: New tests FAIL

- [ ] **Step 3: Update Settings.vue**

Add a "Site Logo" section in the Branding group, after the Site Title field:

1. Add `router` import from `@inertiajs/vue3`
2. Add a reactive `logoFile` ref
3. Add `uploadLogo` function that uses `router.post()` with FormData
4. Add `removeLogo` function that uses `router.delete()`
5. Add the template section with: preview image (when logo exists), file input, upload button, remove button, help text

The logo upload is a separate form action from the main settings form — it uses `router.post()` directly instead of `useForm`, since it's a multipart file upload that needs to be submitted independently.

```vue
<!-- After the Site Title FormField, before Accent Color -->
<FormField label="Site Logo" name="logo" :error="logoError">
    <div class="flex items-center gap-4">
        <img
            v-if="props.settings?.site_logo_url"
            :src="props.settings.site_logo_url"
            alt="Current logo"
            class="h-16 w-16 rounded-lg border border-[var(--color-border)]"
            data-testid="logo-preview"
        />
        <div class="space-y-2">
            <input
                type="file"
                accept="image/png,image/jpeg,image/webp"
                data-testid="input-logo"
                class="text-[13px] text-[var(--color-text-secondary)]"
                @change="onLogoSelected"
            />
            <p class="text-[11px] text-[var(--color-text-muted)]">
                Square image, min 64×64, max 2 MB. Used as favicon and logo on user-facing pages.
            </p>
        </div>
    </div>
    <div v-if="props.settings?.site_logo_url" class="mt-2">
        <button
            type="button"
            data-testid="action-remove-logo"
            class="text-[13px] text-[var(--color-danger)] hover:underline"
            @click="removeLogo"
        >
            Remove logo
        </button>
    </div>
</FormField>
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `npx vitest run tests/js/Pages/Admin/Content/Settings.spec.js`
Expected: All tests PASS

- [ ] **Step 5: Run Prettier and ESLint**

Run: `npx prettier --write resources/js/Pages/Admin/Content/Settings.vue && npx eslint resources/js/Pages/Admin/Content/Settings.vue`
Expected: Clean

- [ ] **Step 6: Commit**

```bash
git add resources/js/Pages/Admin/Content/Settings.vue tests/js/Pages/Admin/Content/Settings.spec.js
git commit -m "feat: add logo upload UI to general settings page"
```

---

### Task 7: Playwright E2E Tests

**Files:**
- Modify: `tests/e2e/admin-settings.spec.js`
- Modify: `tests/e2e/captive.spec.js`
- Modify: `tests/e2e/portal.spec.js`

- [ ] **Step 1: Add settings logo E2E tests**

In `tests/e2e/admin-settings.spec.js`, add a new describe block:

```javascript
test.describe('Logo Upload', () => {
    test('settings page has logo upload input', async ({ page }) => {
        await page.goto('/admin/content/settings');
        await expect(page.getByTestId('input-logo')).toBeVisible();
    });

    test('settings page shows help text for logo', async ({ page }) => {
        await page.goto('/admin/content/settings');
        await expect(page.getByText('Square image')).toBeVisible();
    });
});
```

- [ ] **Step 2: Add captive portal logo E2E tests**

In `tests/e2e/captive.spec.js`, add:

```javascript
test('captive portal renders default A logo mark when no custom logo', async ({ page }) => {
    await page.goto('/captive');
    const logoMark = page.locator('[data-testid="captive-login"] .font-heading').first();
    await expect(logoMark).toContainText('A');
});
```

- [ ] **Step 3: Add portal dashboard logo E2E tests**

In `tests/e2e/portal.spec.js`, add:

```javascript
test('portal renders app logo', async ({ page }) => {
    await page.goto('/portal');
    await expect(page.getByTestId('app-logo')).toBeVisible();
});
```

- [ ] **Step 4: Run Playwright E2E tests**

Run: `npx playwright test tests/e2e/admin-settings.spec.js tests/e2e/captive.spec.js tests/e2e/portal.spec.js`
Expected: All tests PASS

- [ ] **Step 5: Commit**

```bash
git add tests/e2e/admin-settings.spec.js tests/e2e/captive.spec.js tests/e2e/portal.spec.js
git commit -m "test: add Playwright E2E tests for logo feature"
```

---

### Task 8: Quality Gate — Full Suite Verification

**Files:** None (verification only)

- [ ] **Step 1: Run PHP linter**

Run: `vendor/bin/pint --dirty --format agent`
Expected: Clean

- [ ] **Step 2: Run PHPStan**

Run: `vendor/bin/phpstan analyse`
Expected: 0 errors

- [ ] **Step 3: Run full PHP test suite**

Run: `php artisan test --compact`
Expected: All tests pass

- [ ] **Step 4: Run Vitest**

Run: `npx vitest run`
Expected: All tests pass

- [ ] **Step 5: Run ESLint**

Run: `npx eslint resources/js/`
Expected: Clean (or only pre-existing errors)

- [ ] **Step 6: Run Prettier**

Run: `npx prettier --check resources/js/ resources/css/`
Expected: Clean

- [ ] **Step 7: Run full Playwright suite**

Run: `npx playwright test`
Expected: All tests pass
