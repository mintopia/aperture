<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateGeneralSettingsRequest;
use App\Models\Page;
use App\Models\Setting;
use App\Services\CoverImageService;
use App\Services\LogoService;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rules\File;
use Inertia\Inertia;
use Inertia\Response;

class GeneralSettingsController extends Controller
{
    public function __construct(
        private readonly LogoService $logoService,
        private readonly CoverImageService $coverImageService,
    ) {}

    public function show(): Response
    {
        return Inertia::render('Admin/Content/Settings', [
            'settings' => [
                'site_title' => Setting::get('general.site_title', ''),
                'terms_type' => Setting::get('general.terms_type', 'url'),
                'terms_value' => Setting::get('general.terms_value'),
                'privacy_type' => Setting::get('general.privacy_type', 'url'),
                'privacy_value' => Setting::get('general.privacy_value'),
                'theme_mode' => Setting::get('theme.mode', config('aperture.theme.mode')),
                'accent_hue' => (int) Setting::get('theme.accent_hue', config('aperture.theme.accent_hue')),
                'accent_chroma' => (float) Setting::get('theme.accent_chroma', config('aperture.theme.accent_chroma')),
                'accent_lightness' => (int) Setting::get('theme.accent_lightness', config('aperture.theme.accent_lightness')),
                'custom_css' => Setting::get('theme.custom_css'),
                'site_logo_url' => $this->logoService->url(),
                'cover_image_url' => $this->coverImageService->url(),
            ],
            'pages' => Page::orderBy('title')->get(),
            'breadcrumbs' => [
                ['label' => 'Admin', 'href' => route('admin.home')],
                ['label' => 'Content', 'href' => route('admin.content.index')],
                ['label' => 'Settings'],
            ],
        ]);
    }

    public function update(UpdateGeneralSettingsRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        Setting::set('general.site_title', 'Site Title', $validated['site_title']);
        Setting::set('general.terms_type', 'Terms Type', $validated['terms_type']);
        Setting::set('general.terms_value', 'Terms Value', $validated['terms_value'] ?? null);
        Setting::set('general.privacy_type', 'Privacy Type', $validated['privacy_type']);
        Setting::set('general.privacy_value', 'Privacy Value', $validated['privacy_value'] ?? null);

        Setting::set('theme.mode', 'Theme Mode', $validated['theme_mode']);
        Setting::set('theme.accent_hue', 'Accent Hue', (string) $validated['accent_hue']);
        Setting::set('theme.accent_chroma', 'Accent Chroma', (string) ($validated['accent_chroma'] ?? config('aperture.theme.accent_chroma')));
        Setting::set('theme.accent_lightness', 'Accent Lightness', (string) ($validated['accent_lightness'] ?? config('aperture.theme.accent_lightness')));
        Setting::set('theme.custom_css', 'Custom CSS', $validated['custom_css'] ?? null);

        return back()->with('success', 'Settings updated.');
    }

    public function updateLogo(Request $request): RedirectResponse
    {
        $request->validate([
            'logo' => [
                'required',
                File::image()->types(['png', 'jpg', 'jpeg', 'webp'])->max(2048),
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! $value instanceof UploadedFile) {
                        return;
                    }

                    $size = @getimagesize($value->getRealPath());
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

    public function updateCoverImage(Request $request): RedirectResponse
    {
        $request->validate([
            'cover_image' => [
                'required',
                File::image()->types(['png', 'jpg', 'jpeg', 'webp'])->max(5120),
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! $value instanceof UploadedFile) {
                        return;
                    }

                    $size = @getimagesize($value->getRealPath());
                    if ($size === false) {
                        $fail('The cover image must be a valid image.');

                        return;
                    }

                    [$width] = $size;
                    if ($width < 600) {
                        $fail('The cover image must be at least 600 pixels wide.');
                    }
                },
            ],
        ]);

        $this->coverImageService->store($request->file('cover_image'));

        return back()->with('success', 'Cover image uploaded.');
    }

    public function deleteCoverImage(): RedirectResponse
    {
        $this->coverImageService->delete();

        return back()->with('success', 'Cover image removed.');
    }
}
