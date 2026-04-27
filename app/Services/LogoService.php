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

        $this->savePng($image, $disk->path(self::BRANDING_DIR.'/logo.png'));

        foreach (self::FAVICON_SIZES as $size => $filename) {
            $resized = $this->resize($image, (int) $size, (int) $size);
            $this->savePng($resized, $disk->path(self::BRANDING_DIR.'/'.$filename));
            imagedestroy($resized);
        }

        imagedestroy($image);

        Setting::set('general.site_logo', 'Site Logo', 'true');
    }

    public function delete(): void
    {
        $disk = Storage::disk('public');

        $disk->delete(self::BRANDING_DIR.'/logo.png');
        foreach (self::FAVICON_SIZES as $filename) {
            $disk->delete(self::BRANDING_DIR.'/'.$filename);
        }

        $setting = Setting::whereCode('general.site_logo')->first();
        $setting?->delete();
    }

    public function exists(): bool
    {
        return Setting::get('general.site_logo') === 'true'
            && Storage::disk('public')->exists(self::BRANDING_DIR.'/logo.png');
    }

    public function url(): ?string
    {
        if (! $this->exists()) {
            return null;
        }

        $path = Storage::disk('public')->path(self::BRANDING_DIR.'/logo.png');
        $version = file_exists($path) ? filemtime($path) : 0;

        return asset('storage/'.self::BRANDING_DIR.'/logo.png').'?v='.$version;
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
            $path = Storage::disk('public')->path(self::BRANDING_DIR.'/'.$filename);
            $version = file_exists($path) ? filemtime($path) : 0;
            $urls[$size] = asset('storage/'.self::BRANDING_DIR.'/'.$filename).'?v='.$version;
        }

        return $urls;
    }

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
