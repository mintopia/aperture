<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class CoverImageService
{
    private const BRANDING_DIR = 'branding';

    private const SETTING_KEY = 'dashboard.cover_image';

    private const SETTING_NAME = 'Dashboard Cover Image';

    private const ALLOWED_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp'];

    private ?bool $existsCache = null;

    public function store(UploadedFile $file): void
    {
        $disk = Storage::disk('public');
        $disk->makeDirectory(self::BRANDING_DIR);

        // Remove any existing cover image before storing a new one
        $this->deleteFile();

        $extension = $this->resolveExtension($file);
        $filename = 'cover.'.$extension;

        $file->storeAs(self::BRANDING_DIR, $filename, 'public');

        $url = asset('storage/'.self::BRANDING_DIR.'/'.$filename);
        Setting::set(self::SETTING_KEY, self::SETTING_NAME, $url);
        $this->existsCache = true;
    }

    public function delete(): void
    {
        $this->deleteFile();

        $setting = Setting::whereCode(self::SETTING_KEY)->first();
        $setting?->delete();
        $this->existsCache = false;
    }

    public function exists(): bool
    {
        if ($this->existsCache !== null) {
            return $this->existsCache;
        }

        $settingValue = Setting::get(self::SETTING_KEY);
        if ($settingValue === null) {
            $this->existsCache = false;

            return false;
        }

        $filename = $this->filenameFromSetting($settingValue);
        $this->existsCache = $filename !== null
            && Storage::disk('public')->exists(self::BRANDING_DIR.'/'.$filename);

        return $this->existsCache;
    }

    public function url(): ?string
    {
        if (! $this->exists()) {
            return null;
        }

        /** @var string $settingValue */
        $settingValue = Setting::get(self::SETTING_KEY);

        /** @var string $filename */
        $filename = $this->filenameFromSetting($settingValue);

        $path = Storage::disk('public')->path(self::BRANDING_DIR.'/'.$filename);
        $version = file_exists($path) ? filemtime($path) : 0;

        return asset('storage/'.self::BRANDING_DIR.'/'.$filename).'?v='.$version;
    }

    private function resolveExtension(UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if (in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return $extension;
        }

        return 'png';
    }

    private function filenameFromSetting(mixed $settingValue): ?string
    {
        if (! is_string($settingValue)) {
            return null;
        }

        foreach (self::ALLOWED_EXTENSIONS as $ext) {
            if (str_ends_with($settingValue, 'cover.'.$ext)) {
                return 'cover.'.$ext;
            }
        }

        return null;
    }

    private function deleteFile(): void
    {
        $disk = Storage::disk('public');

        foreach (self::ALLOWED_EXTENSIONS as $ext) {
            $path = self::BRANDING_DIR.'/cover.'.$ext;
            if ($disk->exists($path)) {
                $disk->delete($path);
            }
        }
    }
}
