# Custom Logo & Favicon Upload

## Overview

Allow site admins to upload a custom image that replaces the default branding on user-facing pages. The image is used as the site favicon, replaces the hardcoded "A" logo mark on the captive portal, and appears as a small logo next to the site name in the user dashboard header. The admin UI remains branded "Aperture" — the custom logo only affects user-facing surfaces.

## Constraints

- Accepted formats: PNG, JPG, WebP
- Must be square (1:1 aspect ratio)
- Minimum dimensions: 64x64
- Maximum file size: 2MB
- Original resized to max 512x512 on upload

## Storage

Files are stored in `storage/app/public/branding/`:

| File                    | Size    | Purpose                        |
|------------------------|---------|--------------------------------|
| `logo.png`             | max 512 | Original (captive portal, dashboard header) |
| `favicon-16x16.png`    | 16x16   | Standard favicon               |
| `favicon-32x32.png`    | 32x32   | Standard favicon               |
| `apple-touch-icon.png` | 180x180 | iOS home screen icon           |

All files are PNG regardless of upload format. Served via the existing `public/storage` symlink.

A `Setting::set('general.site_logo', 'Site Logo', 'true')` flag indicates a custom logo is present. When the logo is removed, the setting is deleted and all files in `storage/app/public/branding/` are removed.

## Backend Components

### Image Processing Service

New service: `App\Services\LogoService`

Responsibilities:
- Validate and process uploaded image (resize, generate favicon variants)
- Store files to `storage/app/public/branding/`
- Delete all branding files on removal
- Return logo URL with cache-busting query string

Uses GD extension (already available in the Docker environment). Converts all uploads to PNG for consistency.

Methods:
- `store(UploadedFile $file): void` — validate dimensions/aspect, resize original to 512x512 max, generate favicon variants, save all to disk, set the setting flag
- `delete(): void` — remove all files from `branding/` directory, delete the setting
- `exists(): bool` — check if a logo is configured
- `url(): ?string` — return `/storage/branding/logo.png?v={filemtime}` or null
- `faviconUrls(): ?array` — return array of favicon URLs keyed by size (`'16'`, `'32'`, `'180'`), or null if no logo

### GeneralSettingsController Changes

Two new methods added to `App\Http\Controllers\Admin\GeneralSettingsController`:

**`updateLogo(Request $request): RedirectResponse`**
- Route: `POST /admin/content/settings/logo` (name: `admin.content.settings.logo.update`)
- Validates: `logo` file field — required, image, mimes:png,jpg,jpeg,webp, max:2048
- Custom validation: square aspect ratio, minimum 64x64 dimensions
- Calls `LogoService::store()`
- Redirects back with success message

**`deleteLogo(): RedirectResponse`**
- Route: `DELETE /admin/content/settings/logo` (name: `admin.content.settings.logo.delete`)
- Calls `LogoService::delete()`
- Redirects back with success message

### GeneralSettingsController::show Changes

Add `site_logo_url` to the settings array returned to the frontend:

```php
'site_logo_url' => $logoService->url(),
```

### ThemeService Changes

Add two new entries to the cached theme array:

```php
'has_site_logo' => $logoService->exists(),
'site_logo_url' => $logoService->url(),
'favicon_urls' => $logoService->faviconUrls(),
```

`LogoService` is injected via constructor. The `faviconUrls()` method returns an array:

```php
// Returns null when no logo exists, or:
[
    '16' => '/storage/branding/favicon-16x16.png?v=1714200000',
    '32' => '/storage/branding/favicon-32x32.png?v=1714200000',
    '180' => '/storage/branding/apple-touch-icon.png?v=1714200000',
]
```

### InjectTheme Middleware Changes

Share logo data to Blade views:

```php
View::share('siteLogoUrl', $theme['site_logo_url']);
View::share('hasSiteLogo', $theme['has_site_logo']);
View::share('faviconUrls', $theme['favicon_urls']);
```

### Routes

Added inside the existing admin middleware group in `routes/web.php`, near the content settings routes:

```
POST   /admin/content/settings/logo   → GeneralSettingsController@updateLogo   → admin.content.settings.logo.update
DELETE /admin/content/settings/logo   → GeneralSettingsController@deleteLogo   → admin.content.settings.logo.delete
```

## Frontend Components

### Blade: Favicon Links

Both `resources/views/app.blade.php` and `resources/views/layouts/captive.blade.php` get conditional favicon `<link>` tags in the `<head>`:

```blade
@if($hasSiteLogo ?? false)
    <link rel="icon" type="image/png" sizes="32x32" href="{{ $faviconUrls['32'] }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ $faviconUrls['16'] }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ $faviconUrls['180'] }}">
@endif
```

When no custom logo is set, no favicon tags are rendered (browser uses default behavior).

### Blade: Captive Portal Logo Mark

In `resources/views/captive/login.blade.php`, the hardcoded "A" span is replaced with a conditional:

```blade
@if($hasSiteLogo ?? false)
    <img src="{{ $siteLogoUrl }}" alt="{{ $siteTitle }}" class="h-10 w-10 rounded-[10px]" data-testid="captive-logo-image">
@else
    <div class="mx-auto mb-4 inline-flex h-10 w-10 items-center justify-center rounded-[10px] bg-[var(--color-primary)]">
        <span class="font-heading text-lg font-extrabold tracking-tight text-[var(--color-bg)]">A</span>
    </div>
@endif
```

When a custom logo is set, the colored background `<div>` wrapper is not rendered — the `<img>` replaces the entire block. When no logo is set, the original "A" in the colored square renders as the fallback.

### Vue: AppLogo.vue

`resources/js/Components/AppLogo.vue` is updated to show a small image next to the site name when a logo URL is available:

```vue
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

The `logoUrl` comes from `page.props.theme.site_logo_url`. This component is used in `PortalLayout.vue` (user dashboard) — the admin layout does NOT use this component, so admin UI remains unaffected.

### Vue: General Settings Page

`resources/js/Pages/Admin/Content/Settings.vue` gets a new "Site Branding" section:

- **Position:** After the "Site Title" field, before the Terms & Conditions section
- **Current logo preview:** Shows the current logo as a 64x64 image if one exists, with a "Remove" button
- **Upload field:** Standard file input accepting `image/png, image/jpeg, image/webp`
- **Upload button:** Submits via `router.post()` to the logo upload endpoint (separate from the main settings form since it's multipart)
- **Validation feedback:** Shows errors for invalid files (wrong format, too large, not square, too small)
- **Help text:** "Upload a square image (min 64x64, max 2MB). Used as favicon and logo on user-facing pages."

## Where the Custom Logo Appears

| Surface | Component | What changes |
|---------|-----------|-------------|
| Browser favicon | `app.blade.php`, `captive.blade.php` | `<link rel="icon">` tags added |
| Captive portal login | `captive/login.blade.php` | "A" replaced with `<img>` |
| User dashboard header | `AppLogo.vue` via `PortalLayout.vue` | Small image before site name |

## Where the Custom Logo Does NOT Appear

| Surface | Reason |
|---------|--------|
| Admin sidebar header | Admin UI stays Aperture branded |
| Admin login page | Admin UI stays Aperture branded |
| Admin layout | Uses separate branding, not `AppLogo.vue` |

## Testing

### PHP Feature Tests

- **`LogoServiceTest`** — store processes image correctly (resizes, generates favicons, sets setting), delete removes files and setting, exists/url return correct values, rejects non-square images, rejects images below 64x64
- **`GeneralSettingsControllerLogoTest`** — upload validates format/size/dimensions/aspect, stores files on valid upload, returns logo URL in settings show, delete removes logo, non-admin users denied access to both endpoints
- **`ThemeServiceTest`** — updated to verify `has_site_logo` and `site_logo_url` included in theme output

### Vitest

- **`AppLogo.spec.js`** — updated: renders image + text when `site_logo_url` present, renders text-only when absent, image has correct alt text and data-testid
- **`Content/Settings.spec.js`** — updated: branding section renders, shows current logo preview when set, shows file input, remove button calls delete endpoint

### Playwright E2E Tests

- **`settings-logo.spec.ts`** — upload flow: navigate to settings, upload valid image, verify success message and preview appears, verify favicon link in page head. Remove flow: click remove, verify logo preview gone and favicon links removed.
- **`captive-portal-logo.spec.ts`** — with logo: verify `<img>` appears instead of "A" text. Without logo: verify "A" fallback renders.
- **`dashboard-logo.spec.ts`** — with logo: verify app-logo-image appears in dashboard header. Without logo: verify text-only rendering.

## Configuration

No new config files. Uses existing `Setting` model for the logo flag and existing `storage/app/public` filesystem.

## Migration Path

1. Create `LogoService`
2. Add upload/delete routes and controller methods
3. Update `ThemeService` and `InjectTheme` to share logo data
4. Add favicon links to blade layouts
5. Update captive portal blade template
6. Update `AppLogo.vue`
7. Add branding section to General Settings page
8. All test suites
