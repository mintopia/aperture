<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Setting;
use App\Services\CoverImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CoverImageServiceTest extends TestCase
{
    use RefreshDatabase;

    private CoverImageService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->service = new CoverImageService;
    }

    public static function storedExtensionProvider(): array
    {
        return [
            'png keeps original extension' => ['cover.png', 'branding/cover.png'],
            'jpeg keeps jpg extension' => ['cover.jpg', 'branding/cover.jpg'],
            'webp keeps webp extension' => ['cover.webp', 'branding/cover.webp'],
            'unknown extension falls back to png' => ['cover.bmp', 'branding/cover.png'],
        ];
    }

    #[DataProvider('storedExtensionProvider')]
    public function test_store_saves_with_expected_extension(string $uploadedName, string $expectedStoredPath): void
    {
        $file = UploadedFile::fake()->image($uploadedName, 1200, 400);

        $this->service->store($file);

        Storage::disk('public')->assertExists($expectedStoredPath);
    }

    public function test_store_sets_dashboard_cover_image_setting(): void
    {
        $file = UploadedFile::fake()->image('cover.png', 1200, 400);

        $this->service->store($file);

        $value = Setting::get('dashboard.cover_image');
        $this->assertNotNull($value);
        $this->assertStringContainsString('storage/branding/cover.png', $value);
    }

    public function test_store_replaces_previous_cover_image(): void
    {
        $file1 = UploadedFile::fake()->image('cover.png', 1200, 400);
        $file2 = UploadedFile::fake()->image('cover.jpg', 1200, 400);

        $this->service->store($file1);
        Storage::disk('public')->assertExists('branding/cover.png');

        $this->service->store($file2);
        Storage::disk('public')->assertMissing('branding/cover.png');
        Storage::disk('public')->assertExists('branding/cover.jpg');
    }

    public function test_delete_removes_file_and_clears_setting(): void
    {
        $file = UploadedFile::fake()->image('cover.png', 1200, 400);

        $this->service->store($file);
        Storage::disk('public')->assertExists('branding/cover.png');

        $this->service->delete();

        Storage::disk('public')->assertMissing('branding/cover.png');
        $this->assertNull(Setting::get('dashboard.cover_image'));
    }

    public function test_delete_when_no_cover_image_does_not_error(): void
    {
        $this->service->delete();

        $this->assertFalse($this->service->exists());
    }

    public function test_exists_returns_false_when_no_cover_image(): void
    {
        $this->assertFalse($this->service->exists());
    }

    public function test_exists_returns_true_after_store(): void
    {
        $file = UploadedFile::fake()->image('cover.png', 1200, 400);

        $this->service->store($file);

        $this->assertTrue($this->service->exists());
    }

    public function test_exists_returns_false_when_setting_exists_but_file_missing(): void
    {
        Setting::set('dashboard.cover_image', 'Dashboard Cover Image', 'http://example.com/storage/branding/cover.png');

        $this->assertFalse($this->service->exists());
    }

    public function test_url_returns_null_when_no_cover_image(): void
    {
        $this->assertNull($this->service->url());
    }

    public function test_url_returns_path_with_cache_bust_after_store(): void
    {
        $file = UploadedFile::fake()->image('cover.png', 1200, 400);

        $this->service->store($file);

        $url = $this->service->url();
        $this->assertNotNull($url);
        $this->assertStringContainsString('storage/branding/cover.png', $url);
        $this->assertMatchesRegularExpression('/\?v=\d+$/', $url);
    }

    public function test_exists_returns_false_when_setting_has_non_string_value(): void
    {
        Setting::set('dashboard.cover_image', 'Dashboard Cover Image', 123);

        // Need a fresh service instance to avoid cache
        $service = new CoverImageService;
        $this->assertFalse($service->exists());
    }

    public function test_exists_returns_false_when_setting_has_unrecognised_extension(): void
    {
        Setting::set('dashboard.cover_image', 'Dashboard Cover Image', 'http://example.com/storage/branding/cover.gif');

        // Need a fresh service instance to avoid cache
        $service = new CoverImageService;
        $this->assertFalse($service->exists());
    }
}
