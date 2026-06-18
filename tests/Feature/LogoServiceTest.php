<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\LogoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LogoServiceTest extends TestCase
{
    use RefreshDatabase;

    private LogoService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->service = new LogoService;
    }

    public function test_store_saves_logo_and_favicon_variants(): void
    {
        $file = UploadedFile::fake()->image('logo.png', 256, 256);

        $this->service->store($file);

        $disk = Storage::disk('public');
        $disk->assertExists('branding/logo.png');
        $disk->assertExists('branding/favicon-16x16.png');
        $disk->assertExists('branding/favicon-32x32.png');
        $disk->assertExists('branding/apple-touch-icon.png');
    }

    public function test_store_sets_site_logo_setting(): void
    {
        $file = UploadedFile::fake()->image('logo.png', 256, 256);

        $this->service->store($file);

        $this->assertSame('true', Setting::get('general.site_logo'));
    }

    public function test_store_converts_jpeg_to_png(): void
    {
        $file = UploadedFile::fake()->image('logo.jpg', 256, 256);

        $this->service->store($file);

        Storage::disk('public')->assertExists('branding/logo.png');
    }

    public function test_store_converts_webp_to_png(): void
    {
        $file = UploadedFile::fake()->image('logo.webp', 128, 128);

        $this->service->store($file);

        Storage::disk('public')->assertExists('branding/logo.png');
    }

    public function test_store_resizes_large_image_to_max_512(): void
    {
        $file = UploadedFile::fake()->image('logo.png', 1024, 1024);

        $this->service->store($file);

        $path = Storage::disk('public')->path('branding/logo.png');
        [$width, $height] = getimagesize($path);

        $this->assertLessThanOrEqual(512, $width);
        $this->assertLessThanOrEqual(512, $height);
    }

    public function test_exists_returns_false_when_no_logo(): void
    {
        $this->assertFalse($this->service->exists());
    }

    public function test_exists_returns_true_after_store(): void
    {
        $file = UploadedFile::fake()->image('logo.png', 256, 256);

        $this->service->store($file);

        $this->assertTrue($this->service->exists());
    }

    public function test_url_returns_null_when_no_logo(): void
    {
        $this->assertNull($this->service->url());
    }

    public function test_url_returns_path_with_cache_bust_after_store(): void
    {
        $file = UploadedFile::fake()->image('logo.png', 256, 256);

        $this->service->store($file);

        $url = $this->service->url();
        $this->assertNotNull($url);
        $this->assertStringContainsString('storage/branding/logo.png', $url);
        $this->assertMatchesRegularExpression('/\?v=\d+$/', $url);
    }

    public function test_favicon_urls_returns_null_when_no_logo(): void
    {
        $this->assertNull($this->service->faviconUrls());
    }

    public function test_favicon_urls_returns_keyed_array_after_store(): void
    {
        $file = UploadedFile::fake()->image('logo.png', 256, 256);

        $this->service->store($file);

        $urls = $this->service->faviconUrls();
        $this->assertNotNull($urls);
        $this->assertArrayHasKey('16', $urls);
        $this->assertArrayHasKey('32', $urls);
        $this->assertArrayHasKey('180', $urls);
        $this->assertStringContainsString('favicon-16x16.png', $urls['16']);
        $this->assertStringContainsString('favicon-32x32.png', $urls['32']);
        $this->assertStringContainsString('apple-touch-icon.png', $urls['180']);
        $this->assertMatchesRegularExpression('/\?v=\d+$/', $urls['16']);
    }

    public function test_delete_removes_all_files_and_setting(): void
    {
        $file = UploadedFile::fake()->image('logo.png', 256, 256);

        $this->service->store($file);
        $this->service->delete();

        $disk = Storage::disk('public');
        $disk->assertMissing('branding/logo.png');
        $disk->assertMissing('branding/favicon-16x16.png');
        $disk->assertMissing('branding/favicon-32x32.png');
        $disk->assertMissing('branding/apple-touch-icon.png');

        $this->assertNull(Setting::get('general.site_logo'));
    }

    public function test_store_overwrites_previous_logo(): void
    {
        $file1 = UploadedFile::fake()->image('logo1.png', 100, 100);
        $file2 = UploadedFile::fake()->image('logo2.png', 200, 200);

        $this->service->store($file1);
        $this->service->store($file2);

        Storage::disk('public')->assertExists('branding/logo.png');
        $this->assertTrue($this->service->exists());

        $path = Storage::disk('public')->path('branding/logo.png');
        [$width, $height] = getimagesize($path);
        $this->assertSame(200, $width);
        $this->assertSame(200, $height);
    }

    public function test_delete_when_no_logo_does_not_error(): void
    {
        $this->service->delete();

        $this->assertFalse($this->service->exists());
    }
}
