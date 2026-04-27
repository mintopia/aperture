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
        ['size' => '16', 'file' => 'favicon-16x16.png'],
        ['size' => '32', 'file' => 'favicon-32x32.png'],
        ['size' => '180', 'file' => 'apple-touch-icon.png'],
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

        foreach (self::FAVICON_SIZES as $entry) {
            $dim = (int) $entry['size'];
            $resized = $this->resize($image, $dim, $dim);
            $this->savePng($resized, $disk->path(self::BRANDING_DIR.'/'.$entry['file']));
            imagedestroy($resized);
        }

        imagedestroy($image);

        Setting::set('general.site_logo', 'Site Logo', 'true');
    }

    public function delete(): void
    {
        $disk = Storage::disk('public');

        $disk->delete(self::BRANDING_DIR.'/logo.png');
        foreach (self::FAVICON_SIZES as $entry) {
            $disk->delete(self::BRANDING_DIR.'/'.$entry['file']);
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
        foreach (self::FAVICON_SIZES as $entry) {
            $path = Storage::disk('public')->path(self::BRANDING_DIR.'/'.$entry['file']);
            $version = file_exists($path) ? filemtime($path) : 0;
            /** @var string $size */
            $size = $entry['size'];
            $urls[$size] = asset('storage/'.self::BRANDING_DIR.'/'.$entry['file']).'?v='.$version;
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

    /**
     * @param  positive-int  $width
     * @param  positive-int  $height
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
