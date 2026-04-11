# Aperture Redesign — Implementation Plan

**Date**: 2026-04-10
**Spec**: [2026-04-10-aperture-redesign-design.md](./2026-04-10-aperture-redesign-design.md)

## Phase Overview

| Phase | Name | Dependencies |
|-------|------|-------------|
| 1 | Foundation & Theming | None |
| 2 | Auth Flow & Captive Portal | Phase 1 |
| 3 | User Portal | Phase 2 |
| 4 | Admin Panel & Integrations | Phase 1 |

---

## Phase 1: Foundation & Theming

### 1.1 Install Frontend Dependencies

**NPM packages:**
```bash
npm install vue @vitejs/plugin-vue @inertiajs/vue3 tailwindcss @tailwindcss/vite autoprefixer
```

**Composer packages:**
```bash
composer require inertiajs/inertia-laravel tightenco/ziggy
```

**Config changes:**
- Update `vite.config.js` — add Vue plugin (`@vitejs/plugin-vue`), Tailwind plugin (`@tailwindcss/vite`), configure `resolve.alias` for `@` → `resources/js`
- Create `tailwind.config.js` — extend `colors` with CSS custom property references (e.g. `primary: 'var(--color-primary)'`), set `content` paths to `resources/js/**/*.vue` and `resources/views/**/*.blade.php`
- Create `resources/css/app.css` — Tailwind directives (`@tailwind base; @tailwind components; @tailwind utilities;`) plus base custom property defaults

### 1.2 Inertia Setup

**New files:**
- `app/Http/Middleware/HandleInertiaRequests.php`
  - Extends `Inertia\Middleware`
  - `share()` returns: `auth.user`, `flash` messages, `ziggy` routes, `theme` settings
- `resources/js/app.js`
  - Creates Vue app with `createInertiaApp()`
  - Registers global components (layouts, theme toggle)
  - Imports `resources/css/app.css`
- `resources/views/app.blade.php`
  - Inertia root template with `@inertiaHead`, `@inertia`, Vite directives
  - Includes `@routes` (Ziggy)

**Modified files:**
- `app/Http/Kernel.php` — add `HandleInertiaRequests::class` to `web` middleware group
- `vite.config.js` — ensure `resources/js/app.js` is an entry point

**Commands:**
```bash
php artisan make:middleware HandleInertiaRequests --no-interaction
```

### 1.3 Theming System

**Database migration:**
- `database/migrations/xxxx_add_theme_settings.php`
  - Check existing `Setting` model structure first (`app/Models/Setting.php`, `app/Casts/SettingValue.php`)
  - Add theme-related setting rows via seeder or migration: `theme.name` (default: `cool-neon`), `theme.mode` (default: `dark`), `theme.custom_overrides` (JSON, nullable)
  - If Settings model uses key-value pairs, add rows; if it uses columns, add columns

**New middleware:**
- `app/Http/Middleware/InjectTheme.php`
  - Reads `theme.name` and `theme.mode` from `Setting` model
  - Shares theme data with Inertia via `inertia()->share()`
  - For captive portal (non-Inertia), injects CSS custom properties into Blade via `View::share()`

**Register in `app/Http/Kernel.php`** — add `InjectTheme::class` to `web` middleware group (after `HandleInertiaRequests`)

**Theme CSS files** in `resources/css/themes/`:

| File | Palette | Description |
|------|---------|-------------|
| `cool-neon.css` | Blues, cyans, electric accents | Default theme — clean tech aesthetic |
| `warm-neon.css` | Pinks, magentas, purple highlights | Vibrant retro-future feel |
| `matrix.css` | Greens, limes, teal undertones | Hacker/cyberpunk aesthetic |
| `amber-glow.css` | Oranges, ambers, warm tones | Warm terminal nostalgia |

Each theme file defines both light and dark mode variants using a `[data-theme="<name>"]` and `[data-mode="dark"]` selector strategy. Custom properties per theme:

```
--color-primary            --color-bg
--color-primary-hover      --color-surface
--color-accent             --color-surface-hover
--color-accent-hover       --color-text
--color-success            --color-text-secondary
--color-warning            --color-text-muted
--color-danger             --color-border
--color-info               --color-border-hover
--color-glow               --color-input-bg
```

**Vue composable:**
- `resources/js/composables/useTheme.ts`
  - Reactive `theme` and `mode` refs
  - `toggleMode()` — switches light/dark, persists to localStorage + optionally to API
  - `setTheme(name)` — switches theme, updates `[data-theme]` attribute on `<html>`
  - Reads initial values from Inertia shared data

### 1.4 Base Layouts

**New Vue layouts:**
- `resources/js/Layouts/PortalLayout.vue`
  - Gaming-friendly layout for user portal
  - Includes header with event logo, user avatar, theme toggle, logout
  - Responsive — works on phones (captive portal redirect) through desktops
  - Uses `<slot>` for page content
  - Subtle glow/accent effects via theme custom properties

- `resources/js/Layouts/AdminLayout.vue`
  - Professional SaaS-style layout for admin panel
  - Collapsible sidebar navigation
  - Top bar with global search trigger (Cmd+K), notifications, user menu
  - Breadcrumbs
  - Uses `<slot>` for page content

**Captive portal layout (Blade — stays as Blade):**
- `resources/views/layouts/captive.blade.php`
  - Minimal, self-contained layout
  - Inline or separate small CSS bundle (Vite entry: `resources/css/captive.css`)
  - No CDN dependencies — all assets local
  - Themed via CSS custom properties injected by `InjectTheme` middleware
  - Must work on devices with no JS or limited JS (progressive enhancement)

**Shared Vue components:**
- `resources/js/Components/ThemeToggle.vue` — dark/light mode switch with animation
- `resources/js/Components/AppLogo.vue` — renders event logo from settings, with fallback

### 1.5 Testing

**New test files:**
```bash
php artisan make:test --phpunit InjectThemeMiddlewareTest
php artisan make:test --phpunit HandleInertiaRequestsMiddlewareTest
```

**Test coverage:**
- `tests/Feature/InjectThemeMiddlewareTest.php`
  - Test: theme CSS properties injected into Blade views
  - Test: theme data shared with Inertia
  - Test: falls back to default theme when setting missing
  - Test: handles invalid theme name gracefully

- `tests/Feature/HandleInertiaRequestsMiddlewareTest.php`
  - Test: shares auth user data
  - Test: shares flash messages
  - Test: shares Ziggy routes
  - Test: shares theme data

- **Vite build verification:**
  ```bash
  npm run build
  ```
  Verify no errors, output includes Vue components and Tailwind CSS.

### 1.6 Cleanup

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact
```

Verify all existing tests still pass. No regressions from new dependencies or middleware.

---

## Phase 2: Auth Flow & Captive Portal

### 2.1 OAuth2 Device Flow Backend

**Interface definition:**
- `app/Services/Interfaces/AuthProviderInterface.php`
  ```php
  interface AuthProviderInterface
  {
      public function initiateDeviceFlow(): DeviceFlowResponse;
      public function pollDeviceFlow(string $deviceCode): ?AuthResult;
      public function getUserInfo(string $accessToken): UserInfo;
  }
  ```

**DTOs (create via `php artisan make:class`):**
- `app/Services/Auth/DeviceFlowResponse.php` — `verification_uri`, `device_code`, `user_code`, `expires_in`, `interval`
- `app/Services/Auth/AuthResult.php` — `access_token`, `token_type`, `scope`
- `app/Services/Auth/UserInfo.php` — `id`, `name`, `email`, `avatar`

**Implementation:**
- `app/Services/Auth/BorealisDeviceFlowService.php`
  - Implements `AuthProviderInterface`
  - Uses `Http::` facade for Borealis OAuth2 device flow endpoint
  - `initiateDeviceFlow()` — POST to device authorization endpoint
  - `pollDeviceFlow()` — POST to token endpoint with `urn:ietf:params:oauth:grant-type:device_code`
  - Handles standard OAuth2 device flow responses: `authorization_pending`, `slow_down`, `expired_token`, `access_denied`

**Service registration:**
- `app/Providers/AppServiceProvider.php` — bind `AuthProviderInterface` to `BorealisDeviceFlowService`

**Controller:**
```bash
php artisan make:controller DeviceAuthController --no-interaction
```
- `app/Http/Controllers/DeviceAuthController.php`:
  - `initiate(Request $request)` — starts device flow, stores `device_code` in session/cache, returns verification URI + user code (JSON for AJAX, or redirect for Blade)
  - `poll(string $deviceCode)` — polls auth provider, returns JSON status (`pending`, `complete`, `expired`, `denied`)
  - `complete(Request $request)` — on successful auth: finds/creates `User` and `UserAuthentication`, logs in via `Auth::login()`, dispatches `GrantNetworkAccess` job, returns redirect URL

### 2.2 Captive Portal Frontend

**Blade templates:**

- `resources/views/layouts/captive.blade.php` (update existing or create)
  - Minimal HTML5 layout
  - Includes captive-specific Vite bundle: `@vite('resources/css/captive.css')`
  - Theme CSS custom properties via `InjectTheme` middleware
  - `<meta name="viewport">`  for mobile
  - No external dependencies

- `resources/views/captive/login.blade.php`
  - Large QR code generated server-side using `chillerlan/php-qrcode` (already in `composer.json`)
  - Prominent device code display (large, monospace, easy to read/type)
  - "Scan QR code or visit [URL] and enter code [CODE]" instructions
  - Vanilla JS polling script (included via Vite captive bundle)
  - States: loading → displaying code → polling → success → redirecting
  - Error state with retry button 
  - Auto-refreshes device code on expiry

- `resources/views/captive/interstitial.blade.php`
  - "Granting network access..." message with animation
  - Vanilla JS polls access status endpoint
  - On success: redirects to configured URL (event website, user portal, etc.)
  - Timeout handling with retry option

**Vanilla JS:**
- `resources/js/captive/poll.js`
  - Polls `GET /auth/device/poll/{deviceCode}` at specified interval
  - Handles backoff on `slow_down` response
  - Updates UI state based on response
  - On `complete`: submits to `POST /auth/device/complete` and follows redirect

**Vite config update:**
- `vite.config.js` — add separate entry point:
  ```js
  input: {
      app: 'resources/js/app.js',
      captive: 'resources/js/captive/poll.js',
      captiveCss: 'resources/css/captive.css',
  }
  ```

### 2.3 Access Grant Jobs

```bash
php artisan make:job GrantNetworkAccess --no-interaction
php artisan make:job RevokeNetworkAccess --no-interaction
php artisan make:job ReapplyAccessRules --no-interaction
```

**`app/Jobs/GrantNetworkAccess.php`:**
- Accepts `User` and `IpAddress` (or `UserIpAddress`)
- Resolves `CaptivePortalInterface` from container
- Calls `grantAccess($ipAddress, $user)` on the interface
- Updates `UserIpAddress` record with status, granted_at timestamp
- Dispatches `IpAllowed` event (existing event: `app/Events/IpAllowed.php`)
- Retry: 3 attempts, 10s backoff
- On permanent failure: logs error, marks status as `failed`

**`app/Jobs/RevokeNetworkAccess.php`:**
- Accepts `User` and `IpAddress`
- Calls `revokeAccess($ipAddress, $user)` on `CaptivePortalInterface`
- Updates record status
- Retry: 3 attempts

**`app/Jobs/ReapplyAccessRules.php`:**
- Scheduled job (register in `app/Console/Kernel.php`)
- Fetches all currently-allowed `UserIpAddress` records
- Re-applies access rules for each via `CaptivePortalInterface`
- Useful after firewall restart or network outage
- Runs on configurable schedule (default: every 15 minutes)

### 2.4 Routes

**Add to `routes/web.php`:**
```php
// Device auth flow
Route::prefix('auth/device')->group(function () {
    Route::post('/initiate', [DeviceAuthController::class, 'initiate'])->name('auth.device.initiate');
    Route::get('/poll/{deviceCode}', [DeviceAuthController::class, 'poll'])->name('auth.device.poll');
    Route::post('/complete', [DeviceAuthController::class, 'complete'])->name('auth.device.complete');
});

// Captive portal
Route::get('/captive', [CaptivePortalController::class, 'index'])->name('captive.index');
Route::get('/captive/interstitial', [CaptivePortalController::class, 'interstitial'])->name('captive.interstitial');
```

- Keep all existing auth routes functional as fallback during transition
- Captive portal detection routes remain at existing paths

### 2.5 Testing

```bash
php artisan make:test --phpunit DeviceAuthControllerTest
php artisan make:test --phpunit BorealisDeviceFlowServiceTest
php artisan make:test --phpunit GrantNetworkAccessJobTest
php artisan make:test --phpunit RevokeNetworkAccessJobTest
php artisan make:test --phpunit CaptivePortalViewTest
```

**Test coverage:**

- `tests/Feature/DeviceAuthControllerTest.php`
  - Test: `initiate` returns device code and verification URI
  - Test: `poll` returns `pending` while waiting
  - Test: `poll` returns `complete` after auth
  - Test: `poll` returns `expired` after timeout
  - Test: `complete` creates user and logs in
  - Test: `complete` dispatches `GrantNetworkAccess` job
  - Test: `complete` rejects invalid device code
  - Test: rate limiting on poll endpoint

- `tests/Unit/BorealisDeviceFlowServiceTest.php`
  - Test: `initiateDeviceFlow` sends correct HTTP request
  - Test: `pollDeviceFlow` handles `authorization_pending`
  - Test: `pollDeviceFlow` handles `slow_down` (increases interval)
  - Test: `pollDeviceFlow` handles successful token response
  - Test: `pollDeviceFlow` handles `expired_token`
  - Test: `pollDeviceFlow` handles `access_denied`
  - Mock HTTP responses using `Http::fake()`

- `tests/Feature/GrantNetworkAccessJobTest.php`
  - Test: job calls `CaptivePortalInterface::grantAccess()`
  - Test: job updates `UserIpAddress` status
  - Test: job dispatches `IpAllowed` event
  - Test: job retries on transient failure
  - Test: job marks as failed on permanent error

- `tests/Feature/RevokeNetworkAccessJobTest.php`
  - Test: job calls `CaptivePortalInterface::revokeAccess()`
  - Test: job updates record status

- `tests/Feature/CaptivePortalViewTest.php`
  - Test: captive login page renders QR code
  - Test: captive login page displays device code
  - Test: interstitial page renders
  - Test: pages work without JavaScript (basic content visible)

### 2.6 Cleanup

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact
```

Verify all Phase 1 and Phase 2 tests pass. Verify existing tests still pass.

---

## Phase 3: User Portal

### 3.1 Dashboard Page

**Controller:**
```bash
php artisan make:controller Portal/DashboardController --no-interaction
```

- `app/Http/Controllers/Portal/DashboardController.php`:
  - `index()` — returns Inertia render of `Portal/Dashboard`
  - Shares: authenticated user, user's IP addresses (with status), active content blocks (ordered by `sort_order`), connection info (current IP, DNS status, bandwidth summary)
  - Uses `ContentBlock::where('is_active', true)->orderBy('sort_order')->get()`

**Vue page:**
- `resources/js/Pages/Portal/Dashboard.vue`
  - Uses `PortalLayout`
  - Renders content blocks in a responsive grid
  - Each block is a dynamic component resolved by `type`
  - Welcome message with user's name
  - Quick-status indicators (connected, bandwidth, DNS)

**Route (add to `routes/web.php`):**
```php
Route::middleware(['auth'])->prefix('portal')->group(function () {
    Route::get('/', [Portal\DashboardController::class, 'index'])->name('portal.dashboard');
});
```

### 3.2 Content Block System

**Migration:**
```bash
php artisan make:migration create_content_blocks_table --no-interaction
```

- `database/migrations/xxxx_create_content_blocks_table.php`:
  ```
  Schema::create('content_blocks', function (Blueprint $table) {
      $table->id();
      $table->string('type');           // enum-like: event_info, connection_status, bandwidth, network_stats, pihole_toggle, dns_warning, custom_markdown
      $table->string('title');
      $table->text('content')->nullable(); // markdown content for custom blocks
      $table->integer('sort_order')->default(0);
      $table->boolean('is_active')->default(true);
      $table->json('settings')->nullable(); // per-block config
      $table->timestamps();
  });
  ```

**Model:**
```bash
php artisan make:model ContentBlock --factory --seeder --no-interaction
```

- `app/Models/ContentBlock.php`:
  - Fillable: `type`, `title`, `content`, `sort_order`, `is_active`, `settings`
  - Cast `settings` to `array`, `is_active` to `boolean`
  - Scope `active()` — `where('is_active', true)->orderBy('sort_order')`

- `database/factories/ContentBlockFactory.php` — sensible defaults per type
- `database/seeders/ContentBlockSeeder.php` — default blocks for fresh install

**Vue block components** in `resources/js/Components/Blocks/`:

| Component | Props | Description |
|-----------|-------|-------------|
| `EventInfoBlock.vue` | `title`, `content` (markdown) | Renders markdown using a lightweight parser (e.g. `marked`) |
| `ConnectionStatusBlock.vue` | `ipAddresses`, `dnsStatus`, `connectionSince` | Shows IP(s), connection duration, DNS status indicator |
| `BandwidthBlock.vue` | `bandwidthData` (time series) | Chart of personal bandwidth (use lightweight chart lib or CSS) |
| `NetworkStatsBlock.vue` | `stats` (aggregate) | Total users, bandwidth, uptime |
| `PiHoleToggleBlock.vue` | `isEnabled`, `userIp` | Toggle switch for PiHole ad blocking per user |
| `DnsWarningBlock.vue` | `hasDnsIssue`, `expectedDns`, `actualDns` | Alert banner if user's DNS is misconfigured |
| `CustomMarkdownBlock.vue` | `title`, `content` | Generic markdown block for admin-defined content |

**Block grid container:**
- `resources/js/Components/BlockGrid.vue`
  - CSS Grid layout, responsive (1 col mobile → 2-3 cols desktop)
  - Dynamic component rendering based on block `type`
  - Maps block types to Vue components

### 3.3 PiHole Integration

**Interface:**
- `app/Services/Interfaces/DnsBlockingInterface.php`:
  ```php
  interface DnsBlockingInterface
  {
      public function isEnabledForIp(string $ipAddress): bool;
      public function enableForIp(string $ipAddress): void;
      public function disableForIp(string $ipAddress): void;
  }
  ```

**Controller:**
```bash
php artisan make:controller Portal/PiHoleController --no-interaction
```

- `app/Http/Controllers/Portal/PiHoleController.php`:
  - `toggle(Request $request)` — toggles PiHole for user's current IP
  - Returns JSON with new status
  - Validates user owns the IP address

**Route:**
```php
Route::middleware(['auth'])->prefix('portal')->group(function () {
    Route::post('/pihole/toggle', [Portal\PiHoleController::class, 'toggle'])->name('portal.pihole.toggle');
});
```

### 3.4 Bandwidth/Stats

**Controller:**
```bash
php artisan make:controller Portal/StatsController --no-interaction
```

- `app/Http/Controllers/Portal/StatsController.php`:
  - `bandwidth(Request $request)` — returns JSON bandwidth data for authenticated user's IPs
  - Uses `TrafficMonitorInterface` (backed by `NtopNgService`)
  - Time range parameter: `?range=1h|6h|24h|7d` (default: `24h`)

**Route:**
```php
Route::middleware(['auth'])->prefix('portal')->group(function () {
    Route::get('/stats/bandwidth', [Portal\StatsController::class, 'bandwidth'])->name('portal.stats.bandwidth');
});
```

### 3.5 IPv6 Registration

- Migrate existing IPv6 registration functionality to the new Vue portal layout
- Create `resources/js/Pages/Portal/Ipv6.vue` — form for registering additional IPv6 addresses
- Update existing controller to return Inertia responses (check existing controller in `app/Http/Controllers/`)
- Keep existing validation and business logic unchanged

### 3.6 Testing

```bash
php artisan make:test --phpunit Portal/DashboardControllerTest
php artisan make:test --phpunit Portal/PiHoleControllerTest
php artisan make:test --phpunit Portal/StatsControllerTest
php artisan make:test --phpunit ContentBlockModelTest --unit
```

**Test coverage:**

- `tests/Feature/Portal/DashboardControllerTest.php`
  - Test: authenticated user sees dashboard
  - Test: unauthenticated user redirected to login
  - Test: dashboard includes active content blocks
  - Test: content blocks ordered by sort_order
  - Test: inactive blocks not shown

- `tests/Feature/Portal/PiHoleControllerTest.php`
  - Test: toggle enables PiHole for user's IP
  - Test: toggle disables PiHole for user's IP
  - Test: rejects toggle for IP not owned by user
  - Test: unauthenticated user rejected
  - Test: handles PiHole service unavailable gracefully

- `tests/Feature/Portal/StatsControllerTest.php`
  - Test: returns bandwidth data for user's IPs
  - Test: respects time range parameter
  - Test: rejects invalid time range
  - Test: unauthenticated user rejected
  - Test: handles traffic monitor unavailable gracefully

- `tests/Unit/ContentBlockModelTest.php`
  - Test: `active()` scope returns only active blocks
  - Test: `active()` scope orders by sort_order
  - Test: settings cast to array
  - Test: factory creates valid model

### 3.7 Cleanup

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact
```

Verify all Phase 1, 2, and 3 tests pass.

---

## Phase 4: Admin Panel & Integrations

### 4.1 Admin Layout & Navigation

**Update existing controller:**
- `app/Http/Controllers/Admin/HomeController.php` — change `index()` to return `Inertia::render('Admin/Dashboard', [...])` with summary stats

**Vue pages:**
- `resources/js/Pages/Admin/Dashboard.vue`
  - Uses `AdminLayout`
  - Summary cards: total users, online users, total IPs, bandwidth, active alerts
  - Quick links to common admin actions
  - Recent activity feed

**Vue components:**
- `resources/js/Components/Admin/Sidebar.vue`
  - Collapsible sidebar navigation
  - Sections: Dashboard, Users, IP Addresses, Ports, DHCP, Stats, Content, Settings
  - Active state highlighting, icon per section
  - Collapses to icons on small screens

- `resources/js/Components/Admin/GlobalSearch.vue`
  - Cmd+K / Ctrl+K trigger
  - Modal overlay with search input
  - Live results grouped by type (users, IPs, MACs, ports)
  - Keyboard navigation (arrow keys, Enter to select)
  - Recent searches

### 4.2 Global Search

**Controller:**
```bash
php artisan make:controller Admin/SearchController --no-interaction
```

- `app/Http/Controllers/Admin/SearchController.php`:
  - `search(Request $request)` — accepts `?q=` query parameter
  - Searches across: `User` (name, email), `IpAddress` (address, MAC), `UserIpAddress` (port info)
  - Returns JSON with results grouped by type
  - Limits to 5 results per type for quick search, full pagination for dedicated search page
  - Validates query length (min 2 chars)

**Route:**
```php
Route::middleware(['auth', 'admin'])->prefix('admin')->group(function () {
    Route::get('/search', [Admin\SearchController::class, 'search'])->name('admin.search');
});
```

### 4.3 User Management

**Update existing controller:**
- `app/Http/Controllers/Admin/UserController.php` — convert all methods to return Inertia responses

**Vue pages** in `resources/js/Pages/Admin/Users/`:

- `Index.vue`
  - Searchable, sortable, filterable user table
  - Filters: role, online status, auth provider
  - Bulk actions: grant access, revoke access, change role
  - Pagination
  - Click row to navigate to user detail

- `Show.vue`
  - User profile header (name, email, avatar, role, auth provider)
  - IP address history table (current + historical)
  - Bandwidth chart (from TrafficMonitorInterface)
  - Action buttons: grant/revoke access, change role, impersonate
  - Activity timeline

- `Edit.vue`
  - Edit user name, role, notes
  - Form validation via Inertia form helper

### 4.4 IP/Network Management

**Update existing controller:**
- `app/Http/Controllers/Admin/IpAddressController.php` — convert to Inertia responses

**Vue pages** in `resources/js/Pages/Admin/Ips/`:

- `Index.vue`
  - IP address table with search (by IP, MAC, username)
  - Filters: status (allowed, blocked, pending), IPv4/IPv6
  - Sortable columns
  - Pagination

- `Show.vue`
  - IP detail: address, MAC, user mapping, switch port
  - Bandwidth chart for this IP
  - Access history (granted/revoked timestamps)
  - Port information (if available from switch)
  - Action buttons: block/unblock, re-grant access

### 4.5 Switch Port Management

**Controller:**
```bash
php artisan make:controller Admin/PortController --no-interaction
```

- `app/Http/Controllers/Admin/PortController.php`:
  - `index()` — lists all ports from `NetworkSwitchInterface`, returns Inertia
  - `show(string $portId)` — port detail with stats, errors, connected device
  - `shutdown(string $portId)` — shut down port (admin action, requires confirmation)
  - `enable(string $portId)` — bring port back up

**Vue pages** in `resources/js/Pages/Admin/Ports/`:

- `Index.vue`
  - Port grid/table showing all switch ports
  - Status indicators: up (green), down (red), admin-down (orange), error (yellow)
  - Connected device info (MAC, IP, user if mapped)
  - Search/filter by status, VLAN, speed

- `Show.vue`
  - Port detail: speed, duplex, VLAN, counters (in/out bytes, errors, discards)
  - Connected device with user link
  - Error rate chart
  - Admin controls: shut/no shut, VLAN change (if supported)

**Routes:**
```php
Route::middleware(['auth', 'admin'])->prefix('admin')->group(function () {
    Route::get('/ports', [Admin\PortController::class, 'index'])->name('admin.ports.index');
    Route::get('/ports/{portId}', [Admin\PortController::class, 'show'])->name('admin.ports.show');
    Route::post('/ports/{portId}/shutdown', [Admin\PortController::class, 'shutdown'])->name('admin.ports.shutdown');
    Route::post('/ports/{portId}/enable', [Admin\PortController::class, 'enable'])->name('admin.ports.enable');
});
```

### 4.6 Integration Interfaces

**Define/refine interfaces** in `app/Services/Interfaces/`:

- `CaptivePortalInterface.php`
  ```php
  interface CaptivePortalInterface
  {
      public function grantAccess(string $ipAddress, User $user): bool;
      public function revokeAccess(string $ipAddress, User $user): bool;
      public function isAllowed(string $ipAddress): bool;
      public function listActiveSessions(): Collection;
  }
  ```

- `NetworkSwitchInterface.php`
  ```php
  interface NetworkSwitchInterface
  {
      public function getPortStatus(string $portId): PortStatus;
      public function getAllPorts(): Collection;
      public function shutdownPort(string $portId): bool;
      public function enablePort(string $portId): bool;
      public function getPortStatistics(string $portId): PortStatistics;
      public function getForwardingDatabase(): Collection; // FDB/MAC table
  }
  ```

- `DhcpInterface.php`
  ```php
  interface DhcpInterface
  {
      public function getPoolStatus(): DhcpPoolStatus;
      public function getLeases(): Collection;
      public function getLease(string $ipAddress): ?DhcpLease;
  }
  ```

- `TrafficMonitorInterface.php`
  ```php
  interface TrafficMonitorInterface
  {
      public function getUserBandwidth(string $ipAddress, string $range = '24h'): BandwidthData;
      public function getAggregateStats(): NetworkStats;
      public function getTopTalkers(int $limit = 10): Collection;
  }
  ```

- `NetworkInventoryInterface.php`
  ```php
  interface NetworkInventoryInterface
  {
      public function getForwardingDatabase(): Collection; // FDB table
      public function getArpTable(): Collection;
      public function resolveIpToPort(string $ipAddress): ?PortMapping;
      public function getDeviceList(): Collection;
  }
  ```

- `AuthProviderInterface.php` (defined in Phase 2)

**Implementations:**

| Interface | Implementation | Notes |
|-----------|---------------|-------|
| `CaptivePortalInterface` | `Firewalls/OpnSenseService.php` | Existing `Firewalls/` dir — update/create |
| `DhcpInterface` | `Firewalls/OpnSenseService.php` | Same service, dual interface |
| `NetworkSwitchInterface` | `CiscoService.php` | Update existing service |
| `TrafficMonitorInterface` | `NtopNgService.php` | Update existing service |
| `NetworkInventoryInterface` | `LibreNmsService.php` | **New** — create service |
| `AuthProviderInterface` | `Auth/BorealisDeviceFlowService.php` | Created in Phase 2 |

**New service:**
```bash
php artisan make:class Services/LibreNmsService --no-interaction
```

**Service binding** in `AppServiceProvider.php`:
```php
$this->app->bind(CaptivePortalInterface::class, OpnSenseService::class);
$this->app->bind(DhcpInterface::class, OpnSenseService::class);
$this->app->bind(NetworkSwitchInterface::class, CiscoService::class);
$this->app->bind(TrafficMonitorInterface::class, NtopNgService::class);
$this->app->bind(NetworkInventoryInterface::class, LibreNmsService::class);
$this->app->bind(AuthProviderInterface::class, BorealisDeviceFlowService::class);
```

### 4.7 Admin Settings Pages

**Controller:**
```bash
php artisan make:controller Admin/SettingsController --no-interaction
php artisan make:controller Admin/ContentController --no-interaction
```

- `app/Http/Controllers/Admin/SettingsController.php`:
  - `integrations()` — show/update integration settings (endpoints, API keys, enable/disable per service)
  - `theme()` — show/update theme settings (active theme, custom overrides)
  - `event()` — show/update event settings (name, logo, dates, description)
  - `portal()` — show/update portal settings (session duration, redirect URL, reset all sessions)

- `app/Http/Controllers/Admin/ContentController.php`:
  - `index()` — list content blocks with drag-reorder
  - `store(Request $request)` — create new block
  - `update(Request $request, ContentBlock $block)` — update block
  - `destroy(ContentBlock $block)` — delete block
  - `reorder(Request $request)` — update sort_order for multiple blocks

**Vue pages** in `resources/js/Pages/Admin/Settings/`:

- `Integrations.vue`
  - Card per integration (OpnSense, Cisco, ntopng, LibreNMS, Borealis)
  - Each card: endpoint URL, API key/credentials (masked), enable/disable toggle
  - Test connection button per integration
  - Save per section

- `Theme.vue`
  - Visual theme picker (preview swatches for each theme)
  - Light/dark mode default selector
  - Custom CSS override field (advanced)
  - Live preview

- `Event.vue`
  - Event name, tagline, dates (start/end)
  - Logo upload
  - Description (markdown editor)

- `Portal.vue`
  - Session timeout setting
  - Default redirect URL after auth
  - "Reset all sessions" button (requires confirmation)
  - Captive portal detection settings

**Content management** in `resources/js/Pages/Admin/Content/`:

- `Index.vue`
  - List of content blocks
  - Drag-to-reorder (using vuedraggable or similar)
  - Toggle active/inactive
  - Edit inline or in modal
  - Markdown editor for custom content blocks
  - Add new block button with type selector

**Routes:**
```php
Route::middleware(['auth', 'admin'])->prefix('admin')->group(function () {
    // Settings
    Route::get('/settings/integrations', [Admin\SettingsController::class, 'integrations'])->name('admin.settings.integrations');
    Route::put('/settings/integrations', [Admin\SettingsController::class, 'updateIntegrations'])->name('admin.settings.integrations.update');
    Route::get('/settings/theme', [Admin\SettingsController::class, 'theme'])->name('admin.settings.theme');
    Route::put('/settings/theme', [Admin\SettingsController::class, 'updateTheme'])->name('admin.settings.theme.update');
    Route::get('/settings/event', [Admin\SettingsController::class, 'event'])->name('admin.settings.event');
    Route::put('/settings/event', [Admin\SettingsController::class, 'updateEvent'])->name('admin.settings.event.update');
    Route::get('/settings/portal', [Admin\SettingsController::class, 'portal'])->name('admin.settings.portal');
    Route::put('/settings/portal', [Admin\SettingsController::class, 'updatePortal'])->name('admin.settings.portal.update');

    // Content blocks
    Route::resource('content', Admin\ContentController::class)->except(['create', 'edit']);
    Route::post('/content/reorder', [Admin\ContentController::class, 'reorder'])->name('admin.content.reorder');
});
```

### 4.8 DHCP & Stats

**Controllers:**
```bash
php artisan make:controller Admin/DhcpController --no-interaction
php artisan make:controller Admin/StatsController --no-interaction
```

- `app/Http/Controllers/Admin/DhcpController.php`:
  - `index()` — DHCP pool overview (utilisation %, scope info)
  - `leases()` — active lease list with search
  - Uses `DhcpInterface`

- `app/Http/Controllers/Admin/StatsController.php`:
  - `index()` — aggregate network stats dashboard
  - `bandwidth()` — bandwidth charts (aggregate + top talkers)
  - `topTalkers()` — JSON endpoint for top bandwidth users
  - Uses `TrafficMonitorInterface`

**Vue pages:**
- `resources/js/Pages/Admin/Dhcp/Index.vue` — pool status, lease table
- `resources/js/Pages/Admin/Stats/Index.vue` — charts, top talkers, aggregate stats

**Routes:**
```php
Route::middleware(['auth', 'admin'])->prefix('admin')->group(function () {
    Route::get('/dhcp', [Admin\DhcpController::class, 'index'])->name('admin.dhcp.index');
    Route::get('/dhcp/leases', [Admin\DhcpController::class, 'leases'])->name('admin.dhcp.leases');
    Route::get('/stats', [Admin\StatsController::class, 'index'])->name('admin.stats.index');
    Route::get('/stats/bandwidth', [Admin\StatsController::class, 'bandwidth'])->name('admin.stats.bandwidth');
    Route::get('/stats/top-talkers', [Admin\StatsController::class, 'topTalkers'])->name('admin.stats.top-talkers');
});
```

### 4.9 Testing

```bash
php artisan make:test --phpunit Admin/DashboardControllerTest
php artisan make:test --phpunit Admin/SearchControllerTest
php artisan make:test --phpunit Admin/UserControllerTest
php artisan make:test --phpunit Admin/IpAddressControllerTest
php artisan make:test --phpunit Admin/PortControllerTest
php artisan make:test --phpunit Admin/SettingsControllerTest
php artisan make:test --phpunit Admin/ContentControllerTest
php artisan make:test --phpunit Admin/DhcpControllerTest
php artisan make:test --phpunit Admin/StatsControllerTest
php artisan make:test --phpunit --unit Services/OpnSenseServiceTest
php artisan make:test --phpunit --unit Services/CiscoServiceTest
php artisan make:test --phpunit --unit Services/NtopNgServiceTest
php artisan make:test --phpunit --unit Services/LibreNmsServiceTest
```

**Test coverage:**

- **Admin controllers (Feature tests):**
  - Each controller: authenticated admin can access, non-admin rejected, unauthenticated rejected
  - CRUD operations where applicable
  - Validation errors returned correctly
  - Search returns correct results

- **Settings persistence (Feature tests):**
  - Test: integration settings save and load correctly
  - Test: theme settings update and persist
  - Test: event settings with logo upload
  - Test: portal reset clears all sessions

- **Global search (Feature tests):**
  - Test: searches users by name
  - Test: searches users by email
  - Test: searches IPs by address
  - Test: searches by MAC address
  - Test: returns grouped results
  - Test: respects result limits
  - Test: rejects short queries

- **Service implementations (Unit tests):**
  - Mock HTTP responses for each external API
  - Test each interface method
  - Test error handling (timeouts, invalid responses, auth failures)
  - Test retry logic where applicable

- **Content management (Feature tests):**
  - Test: CRUD for content blocks
  - Test: reorder updates sort_order
  - Test: active/inactive toggle

### 4.10 Cleanup

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact
```

Run full test suite. All phases must pass. No regressions.

---

## Implementation Notes

### General Principles
- Use `php artisan make:*` for all new files (controllers, models, migrations, jobs, tests)
- Follow existing code conventions — check sibling files before creating new ones
- Use factories for model creation in tests
- Run `vendor/bin/pint --dirty --format agent` after every set of PHP changes
- Keep existing routes working during transition (progressive migration, not big-bang)
- All HTTP assets served locally — no CDN dependencies

### Configuration
- All integration settings stored in DB via `Setting` model, not `.env`
- `.env` used only for app-level config (database, cache, queue, app key)
- Integration credentials stored encrypted in DB

### Frontend
- Vue 3 Composition API with `<script setup>` syntax
- TypeScript for composables and shared utilities
- Tailwind CSS via custom properties for theming
- Captive portal pages remain as Blade with vanilla JS (reliability > features)
- Chart library: evaluate lightweight options (Chart.js or CSS-only for simple cases)

### Testing Strategy
- Feature tests for all HTTP endpoints
- Unit tests for service implementations (mocked HTTP)
- Factory states for common test scenarios
- Run relevant test subset after each change, full suite at phase end

### Migration Path
- Phase 1 can be developed without breaking existing functionality
- Phase 2 adds new routes alongside existing ones
- Phase 3 is net-new (user portal)
- Phase 4 progressively converts existing admin pages
- Old Blade views can be removed once all pages are migrated and verified
